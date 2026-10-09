<?php
declare(strict_types=1);

/** One trusted model choice for every QA stage and scientific tool. */
final class AgentModelPolicy
{
    public const DEFAULT_MODEL = 'deepseek-flash';

    public static function resolve(array $config = []): string
    {
        $model = trim((string)($config['agent_model'] ?? self::DEFAULT_MODEL));
        if ($model === '' || in_array($model, ['deepseek-v4-flash', 'deepseek-v4-flash-vision-exp'], true)) {
            return self::DEFAULT_MODEL;
        }
        return $model;
    }
}
