<?php

namespace App\Support\Audio;

enum SpeechDeliveryStyle: string
{
    case Neutral = 'neutral';
    case Warm = 'warm';
    case Excited = 'excited';
    case Nervous = 'nervous';
    case Apologetic = 'apologetic';
    case Reassuring = 'reassuring';
    case Curious = 'curious';
    case Serious = 'serious';
    case Sad = 'sad';
    case Whispering = 'whispering';

    public function direction(): string
    {
        return match ($this) {
            self::Neutral => '',
            self::Warm => 'warm and friendly, natural conversational delivery',
            self::Excited => 'gently excited and pleased, natural conversational delivery',
            self::Nervous => 'slightly nervous and hesitant, clear natural speech',
            self::Apologetic => 'sincerely apologetic, gentle natural delivery',
            self::Reassuring => 'calm and reassuring, warm natural delivery',
            self::Curious => 'gently curious, natural conversational delivery',
            self::Serious => 'serious and composed, clear natural delivery',
            self::Sad => 'gently sad and subdued, clear natural speech',
            self::Whispering => 'quiet whisper, keep every word clear',
        };
    }
}
