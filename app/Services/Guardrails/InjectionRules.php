<?php

namespace App\Services\Guardrails;

final class InjectionRules
{
    /**
     * @var array<string, string>
     */
    private const RULES = [
        'приказ игнорировать инструкции' => '~(игнорируй|проигнорируй|забудь|ignore|forget|disregard)[^.\n]{0,60}(инструкц|указани|правил|instruction|prompt|rules)~iu',
        'маркер служебной роли' => '~^\s*(system|assistant|developer|служебное сообщение)\s*:~imu',
        'токен служебной роли' => '~(<\|?\s*(system|im_start|im_end|assistant)\s*\|?>|\[/?INST\])~iu',
        'попытка вытащить промпт' => '~(покажи|выведи|раскрой|reveal|print|repeat)[^.\n]{0,40}(промпт|prompt|системн|instructions)~iu',
        'подмена роли' => '~(ты теперь|from now on|new instructions|новые инструкции)~iu',
        'подозрительная цена' => '~(всего\s+)?1\s*руб~iu',
        'длинная закодированная вставка' => '~[A-Za-z0-9+/]{120,}={0,2}~u',
    ];

    public function reason(string $text): ?string
    {
        foreach (self::RULES as $reason => $pattern) {
            if (preg_match($pattern, $text)) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * Вырезать предложения, которые сработали по правилам.
     *
     * @return array{0: string, 1: bool}
     */
    public function redact(string $text): array
    {
        $sentences = preg_split('~(?<=[.!?])\s+|\R+~u', $text) ?: [$text];
        $kept = [];
        $removed = false;

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);

            if ($sentence === '') {
                continue;
            }
            if ($this->reason($sentence) !== null) {
                $removed = true;

                continue;
            }

            $kept[] = $sentence;
        }

        return [implode(' ', $kept), $removed];
    }
}
