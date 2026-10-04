<?php
namespace App\Video;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\{ChoiceType,TextType,TextareaType,HiddenType,SubmitType};
use Symfony\Component\OptionsResolver\OptionsResolver;

final class VideoFormType extends AbstractType
{
    public function getBlockPrefix(): string { return 'video'; }
    public function buildForm(FormBuilderInterface $builder,array $options): void
    {
        $builder->add('title',TextType::class,['attr'=>['maxlength'=>120,'placeholder'=>'A clear title for your video']])
            ->add('summary',TextareaType::class,['label'=>'Description / content','attr'=>['rows'=>3,'maxlength'=>4000,'placeholder'=>'What should the viewer know?']])
            ->add('category',ChoiceType::class,['choices'=>VideoInput::CATEGORIES,'placeholder'=>'Choose a category'])
            ->add('language',ChoiceType::class,['choices'=>VideoInput::LANGUAGES,'placeholder'=>'Choose a language'])
            ->add('content_date',TextType::class,['label'=>'Content date','attr'=>['type'=>'date','placeholder'=>'YYYY-MM-DD']])
            ->add('asset_id',ChoiceType::class,['label'=>'Video asset','choices'=>$options['asset_choices']]);
        foreach(VideoInput::ATTACHMENTS as $field)$builder->add($field,ChoiceType::class,['label'=>$field==='cover_id'?'Video cover image':strtoupper(substr($field,-2)).' subtitles (.vtt)','required'=>false,'placeholder'=>'No file attached','choices'=>$options['attachment_choices'][$field]??[]]);
        $builder->add('save_key',HiddenType::class,['mapped'=>false,'data'=>$options['save_key']])->add('save',SubmitType::class,['label'=>'Save draft']);
    }
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_protection'=>true,'csrf_token_id'=>'video-draft','asset_choices'=>[],'attachment_choices'=>[],'save_key'=>'','data_class'=>null]);
    }
}
