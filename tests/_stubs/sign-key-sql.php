<?php

declare(strict_types=1);

// Only the insert-if-absent / winner lookup used by Config::signKey().
if (!class_exists('rex_sql')) {
    class rex_sql
    {
        public static ?string $winner = null;
        public static bool $insertFails = false;
        public static int $selectFailures = 0;
        public static int $selectCalls = 0;
        public static bool $missingRow = false;

        public static function factory(): self
        {
            return new self();
        }

        public function setQuery(string $query, array $params): void
        {
            if (!str_starts_with($query, 'INSERT INTO rex_config ') || array_slice($params, 0, 2) !== ['massif_media', 'sign_key']) {
                throw new \LogicException('Unexpected sign-key insert');
            }
            if (self::$insertFails) {
                throw new \RuntimeException('Insert failed');
            }
            self::$winner ??= json_decode($params[2], true);
        }

        public function getArray(string $query, array $params): array
        {
            if (!str_starts_with($query, 'SELECT `value` FROM rex_config ') || $params !== ['massif_media', 'sign_key']) {
                throw new \LogicException('Unexpected sign-key lookup');
            }
            ++self::$selectCalls;
            if (self::$selectFailures-- > 0) {
                throw new \RuntimeException('SELECT failed');
            }
            return self::$missingRow || self::$winner === null ? [] : [['value' => json_encode(self::$winner)]];
        }

        public static function _reset(): void
        {
            self::$winner = null;
            self::$insertFails = false;
            self::$selectFailures = self::$selectCalls = 0;
            self::$missingRow = false;
        }
    }
}
