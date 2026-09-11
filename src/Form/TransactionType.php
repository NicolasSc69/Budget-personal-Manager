<?php

namespace App\Form;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Enum\Recurrence;
use App\Repository\AppSettingRepository;
use App\Service\CurrencyResolver;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatorInterface;

class TransactionType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly AppSettingRepository $appSettingRepository,
        private readonly CurrencyResolver $currencyResolver,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('date', null, [
                'label' => 'Date',
                'widget' => 'single_text',
            ])
            ->add('recurrence', EnumType::class, [
                'label' => 'Recurrence',
                'class' => Recurrence::class,
                'choice_label' => static fn (Recurrence $recurrence) => $recurrence->label(),
                'help' => 'Automatically generates the next transactions on their due date.',
            ])
            ->add('recurrenceEndDate', null, [
                'label' => 'Recurrence end date',
                'widget' => 'single_text',
                'required' => false,
                'help' => 'Leave empty for a recurrence without an end. Used to stop proposing this transaction in the recurring transactions list after this date.',
            ])
            ->add('type', ChoiceType::class, [
                'mapped' => false,
                'label' => 'Type',
                'expanded' => true,
                'label_attr' => ['class' => 'radio-inline'],
                'choices' => [
                    'Expense' => 'expense',
                    'Income' => 'income',
                ],
                'constraints' => [new Assert\NotBlank(message: 'Choose a type.')],
            ])
            ->add('amount', NumberType::class, [
                'mapped' => false,
                'label' => 'Amount (%currency%)',
                'label_translation_parameters' => ['%currency%' => $this->currencyResolver->getSymbol($this->appSettingRepository->getSettings()->getCurrency())],
                'html5' => true,
                'scale' => 2,
                'attr' => ['step' => '0.01', 'min' => '0.01'],
                'help' => 'Always a positive amount: the type above determines whether it\'s an expense or income.',
                'constraints' => [new Assert\Positive(message: 'The amount must be positive.')],
            ])
            ->add('account', EntityType::class, [
                'label' => 'Account',
                'class' => Account::class,
                'choice_label' => static fn (Account $account) => $account->getLabel().($account->getPerson() ? ' ('.$account->getPerson()->getName().')' : ''),
                'placeholder' => 'Choose an account',
                'query_builder' => static fn ($repository) => $repository->createQueryBuilder('a')->orderBy('a.label', 'ASC'),
            ])
            ->add('destinationAccount', EntityType::class, [
                'label' => 'Destination account',
                'class' => Account::class,
                'choice_label' => static fn (Account $account) => $account->getLabel().($account->getPerson() ? ' ('.$account->getPerson()->getName().')' : ''),
                'placeholder' => 'None (no transfer)',
                'required' => false,
                'query_builder' => static fn ($repository) => $repository->createQueryBuilder('a')->orderBy('a.label', 'ASC'),
                'help' => 'If set, automatically creates the reverse transaction (expense ↔ income) in this account, to represent a transfer between accounts.',
            ])
            ->add('category', EntityType::class, [
                'label' => 'Tag',
                'class' => Category::class,
                'choice_label' => 'fullName',
                'placeholder' => 'Choose a tag',
                'query_builder' => static fn ($repository) => $repository->createQueryBuilder('c')->orderBy('c.name', 'ASC'),
                'choice_attr' => static fn (Category $category) => ['data-color' => $category->getColor()],
                'help' => 'The parent term = Payee and the child term = Category. E.g.: TRANSFER (parent = Payee) & SALARY (child = Category).',
            ])
            ->add('title', TextType::class, [
                'label' => 'Title',
                'required' => false,
                'attr' => [
                    'maxlength' => 255,
                    'autocomplete' => 'off',
                    'class' => 'pe-4',
                    'placeholder' => 'Search an already used title…',
                ],
                'help' => 'The transaction title, for example "RENT".',
            ])
            ->add('isCheque', CheckboxType::class, [
                'label' => 'Paid by cheque',
                'required' => false,
            ])
            ->add('chequeNumber', TextType::class, [
                'label' => 'Cheque number',
                'required' => false,
                'attr' => ['maxlength' => 50],
            ])
            ->add('redirectTo', HiddenType::class, [
                'mapped' => false,
                'required' => false,
                'data' => $options['redirect_to'] ?? null,
            ])
        ;

        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event): void {
            $transaction = $event->getData();
            $form = $event->getForm();

            if (!$transaction instanceof Transaction) {
                return;
            }

            $magnitude = $form->get('amount')->getData();

            if (null === $magnitude) {
                return;
            }

            $signed = 'expense' === $form->get('type')->getData() ? -(float) $magnitude : (float) $magnitude;
            $transaction->setAmount((string) $signed);

            if ($transaction->isCheque() && !$form->get('chequeNumber')->getData()) {
                $form->get('chequeNumber')->addError(new FormError($this->translator->trans('Please provide the cheque number.')));
            }

            if (null !== $transaction->getDestinationAccount() && null !== $transaction->getAccount() && $transaction->getDestinationAccount()->getId() === $transaction->getAccount()->getId()) {
                $form->get('destinationAccount')->addError(new FormError($this->translator->trans('The destination account must be different from the source account.')));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Transaction::class,
            'redirect_to' => null,
        ]);
        $resolver->setAllowedTypes('redirect_to', ['string', 'null']);
    }
}
