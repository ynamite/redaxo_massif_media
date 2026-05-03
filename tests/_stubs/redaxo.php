<?php

declare(strict_types=1);

// Minimal REDAXO core class stubs for PHPUnit. Loaded only when the real
// classes aren't present (so the same file works both standalone and against a
// real REDAXO if integration scenarios ever need it).

if (!class_exists('rex_config')) {
    class rex_config
    {
        /** @var array<string, array<string, mixed>> */
        private static array $store = [];

        public static function get(string $namespace, ?string $key = null, mixed $default = null): mixed
        {
            if ($key === null) {
                return self::$store[$namespace] ?? [];
            }
            // Match REDAXO core: `?? $default` collapses null values to the
            // default. Use array_key_exists if you need to distinguish.
            return self::$store[$namespace][$key] ?? $default;
        }

        public static function set(string $namespace, string $key, mixed $value): bool
        {
            self::$store[$namespace][$key] = $value;
            return true;
        }

        public static function _reset(): void
        {
            self::$store = [];
        }
    }
}

if (!class_exists('rex_path')) {
    class rex_path
    {
        private static string $base = '';

        public static function _setBase(string $path): void
        {
            self::$base = rtrim($path, '/');
        }

        public static function media(string $file = ''): string
        {
            return self::$base . '/media/' . $file;
        }

        public static function addonAssets(string $addon, string $file = ''): string
        {
            return self::$base . '/assets/addons/' . $addon . '/' . $file;
        }
    }
}

if (!class_exists('rex_url')) {
    class rex_url
    {
        public static function base(string $file = ''): string
        {
            return '/' . $file;
        }

        public static function addonAssets(string $addon, string $file = ''): string
        {
            return '/assets/addons/' . $addon . '/' . $file;
        }
    }
}

if (!class_exists('rex_logger')) {
    class rex_logger
    {
        /** @var list<\Throwable> */
        public static array $logged = [];

        public static function logException(\Throwable $e): void
        {
            self::$logged[] = $e;
        }

        public static function _reset(): void
        {
            self::$logged = [];
        }
    }
}

if (!class_exists('rex_media')) {
    class rex_media
    {
        /** @var array<string, mixed> */
        private array $values;

        public function __construct(private string $filename, array $values = [])
        {
            $this->values = $values;
        }

        public static function get(string $filename): ?self
        {
            return null; // tests construct directly when needed
        }

        public function getFileName(): string
        {
            return $this->filename;
        }

        public function getValue(string $key): mixed
        {
            return $this->values[$key] ?? null;
        }
    }
}

if (!class_exists('rex_dir')) {
    class rex_dir
    {
        public static function delete(string $path, bool $deleteSelf = false): bool
        {
            if (!is_dir($path)) {
                return false;
            }
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            return $deleteSelf ? @rmdir($path) : true;
        }
    }
}

if (!class_exists('rex_file')) {
    class rex_file
    {
        public static function put(string $file, string $content): bool
        {
            $dir = dirname($file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            return file_put_contents($file, $content) !== false;
        }

        public static function get(string $file, ?string $default = null): ?string
        {
            $contents = @file_get_contents($file);
            return $contents === false ? $default : $contents;
        }
    }
}

if (!class_exists('rex')) {
    class rex
    {
        private static bool $isBackend = false;
        private static bool $isDebug = false;

        public static function isBackend(): bool
        {
            return self::$isBackend;
        }

        public static function _setBackend(bool $value): void
        {
            self::$isBackend = $value;
        }

        public static function isDebug(): bool
        {
            return self::$isDebug;
        }

        public static function _setDebug(bool $value): void
        {
            self::$isDebug = $value;
        }
    }
}

if (!class_exists('rex_var')) {
    abstract class rex_var
    {
        public const ENV_FRONTEND = 1;
        public const ENV_BACKEND = 2;
        public const ENV_INPUT = 4;
        public const ENV_OUTPUT = 8;

        /** @var array<string, string> */
        protected array $args = [];

        public static function register(string $name, string $class): void
        {
            // no-op for tests
        }

        public function _setArgs(array $args): void
        {
            $this->args = $args;
        }

        protected function hasArg(string $key, bool $defaultArg = false): bool
        {
            return isset($this->args[$key]) || ($defaultArg && isset($this->args[0]));
        }

        protected function getArg(string $key, mixed $default = null, bool $defaultArg = false): mixed
        {
            if (!$this->hasArg($key, $defaultArg)) {
                return $default;
            }
            return $this->args[$key] ?? $this->args[0];
        }

        protected function getParsedArg(string $key, mixed $default = null, bool $defaultArg = false): mixed
        {
            if (!$this->hasArg($key, $defaultArg)) {
                return $default;
            }
            $value = $this->args[$key] ?? $this->args[0];
            return is_numeric($value) ? $value : "'" . addcslashes($value, "\\'") . "'";
        }

        abstract protected function getOutput(): string|false;

        public function _callGetOutput(): string|false
        {
            return $this->getOutput();
        }
    }
}
