<?php

namespace App\Form;

use App\Entity\Category;
use App\Repository\CategoryRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CategoryType extends AbstractType
{
    public function __construct(private readonly CategoryRepository $categoryRepository)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $category = $options['data'] instanceof Category ? $options['data'] : null;

        $builder
            ->add('name', null, [
                'label' => 'Tag name',
                'attr' => [
                    'autocomplete' => 'off',
                    'class' => 'pe-4',
                    'placeholder' => 'Search an existing tag…',
                ],
            ])
            ->add('color', ColorType::class, [
                'label' => 'Color',
            ])
            ->add('parent', EntityType::class, [
                'label' => 'Parent tag',
                'class' => Category::class,
                'choice_label' => 'fullName',
                'placeholder' => 'None (root tag)',
                'required' => false,
                'choices' => $this->categoryRepository->findAssignableParents($category),
            ])
            ->add('excludedFromForecast', CheckboxType::class, [
                'label' => 'Do not take into account for the projected balance calculation',
                'required' => false,
                'help' => 'Transactions tagged with this category (e.g. the salary itself) are left out of the "Projected balance" figure, to avoid counting them twice alongside the account\'s average salary.',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Category::class,
        ]);
    }
}
