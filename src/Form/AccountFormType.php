<?php

namespace App\Form;

use App\Entity\Account;
use App\Entity\AccountType as AccountTypeEntity;
use App\Entity\Person;
use App\Repository\AppSettingRepository;
use App\Service\CurrencyResolver;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AccountFormType extends AbstractType
{
    public function __construct(
        private readonly AppSettingRepository $appSettingRepository,
        private readonly CurrencyResolver $currencyResolver,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $currencySymbol = $this->currencyResolver->getSymbol($this->appSettingRepository->getSettings()->getCurrency());

        $builder
            ->add('label', null, [
                'label' => 'Label',
                'help' => 'E.g.: Joint account, Savings account…',
            ])
            ->add('type', EntityType::class, [
                'label' => 'Account type',
                'class' => AccountTypeEntity::class,
                'choice_label' => 'name',
                'placeholder' => 'Choose an account type',
                'query_builder' => static fn ($repository) => $repository->createQueryBuilder('t')->orderBy('t.name', 'ASC'),
            ])
            ->add('person', EntityType::class, [
                'label' => 'Person',
                'class' => Person::class,
                'choice_label' => 'name',
                'placeholder' => 'No person',
                'required' => false,
                'query_builder' => static fn ($repository) => $repository->createQueryBuilder('p')->orderBy('p.name', 'ASC'),
            ])
            ->add('initialBalance', NumberType::class, [
                'label' => 'Initial balance (%currency%)',
                'label_translation_parameters' => ['%currency%' => $currencySymbol],
                'required' => false,
                'html5' => true,
                'scale' => 2,
                'attr' => ['step' => '0.01'],
                'help' => 'Account balance before the first recorded transaction. Leave empty to start from 0.',
            ])
            ->add('averageSalary', NumberType::class, [
                'label' => 'Average salary (%currency%)',
                'label_translation_parameters' => ['%currency%' => $currencySymbol],
                'required' => false,
                'html5' => true,
                'scale' => 2,
                'attr' => ['step' => '0.01', 'min' => '0'],
                'help' => 'Added to the upcoming balance to compute the projected balance.',
            ])
            ->add('isSavings', CheckboxType::class, [
                'label' => 'Savings',
                'required' => false,
                'help' => 'Shows the interest calculation on this account\'s dashboard.',
            ])
            ->add('interestRate', NumberType::class, [
                'label' => 'Rate (%)',
                'required' => false,
                'html5' => true,
                'scale' => 2,
                'attr' => ['step' => '0.01', 'min' => '0'],
            ])
            ->add('maxAmount', NumberType::class, [
                'label' => 'Maximum amount (%currency%)',
                'label_translation_parameters' => ['%currency%' => $currencySymbol],
                'required' => false,
                'html5' => true,
                'scale' => 2,
                'attr' => ['step' => '0.01', 'min' => '0'],
                'help' => 'Shows a progress bar of the balance against this target.',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Account::class,
        ]);
    }
}
