<?php
namespace App\Form;

use App\Entity\Calibration;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class CalibrationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('scaleWeight', NumberType::class, [
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Please enter a weight']),
                    new Assert\Positive(['message' => 'Weight must be greater than 0']),
                ],
                'attr' => [
                    'min' => 0.1,
                    'step' => 0.1
                ]
            ])
            ->add('airPressure', NumberType::class, [
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Positive()
                ]
            ])
            ->add('ambientAirPressure', NumberType::class, [
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Positive()
                ]
            ])
            ->add('airTemperature', NumberType::class, [
                'constraints' => [
                    new Assert\NotBlank()
                ]
            ])
            ->add('elevation', NumberType::class, [
                'constraints' => [
                    new Assert\NotBlank()
                ]
            ])
            ->add('comment', TextareaType::class, [
                'required' => false
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Calibration::class,
        ]);
    }
}