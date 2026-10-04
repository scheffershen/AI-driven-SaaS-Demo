<?php
namespace App\Video;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;

final class VideoInput
{
    public const CATEGORIES=['Getting Started'=>'getting-started','Product Updates'=>'product-updates','Training'=>'training'];
    public const LANGUAGES=['English'=>'en','French'=>'fr','German'=>'de','Italian'=>'it','Spanish'=>'es'];
    public const ATTACHMENTS=['cover_id','subtitle_en','subtitle_fr','subtitle_de','subtitle_it','subtitle_es'];
    public const FIELDS=['title','summary','category','language','content_date','asset_id',...self::ATTACHMENTS];
    public static function errors(array $payload): array
    {
        $constraints=new Assert\Collection([
            'title'=>[new Assert\Type('string'),new Assert\NotBlank(),new Assert\Length(max:120)],
            'summary'=>[new Assert\Type('string'),new Assert\NotBlank(),new Assert\Length(max:4000)],
            'category'=>[new Assert\Choice(array_values(self::CATEGORIES))],
            'language'=>[new Assert\Choice(array_values(self::LANGUAGES))],
            'content_date'=>[new Assert\NotBlank(),new Assert\Date()],
            'asset_id'=>[new Assert\Type('string'),new Assert\NotBlank(),new Assert\Length(max:64)],
            ...array_fill_keys(self::ATTACHMENTS,new Assert\Optional([new Assert\Type('string'),new Assert\Length(max:32)])),
        ]);
        $errors=[];
        foreach (Validation::createValidator()->validate($payload,$constraints) as $violation) $errors[trim($violation->getPropertyPath(),'[]')][]=$violation->getMessage();
        return $errors;
    }
}
