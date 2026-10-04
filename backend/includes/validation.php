<?php
/**
 * Lightweight validator.
 *
 *   $v = Validator::make($body, [
 *       'name'   => 'required|string|max:100',
 *       'email'  => 'nullable|email|max:150',
 *       'phone'  => 'required|phone',
 *       'guests' => 'required|integer|between:1,20',
 *       'date'   => 'required|date|after_or_today',
 *       'type'   => 'required|in:delivery,takeaway,dine_in',
 *   ]);
 *   if ($v->fails()) json_validation_error($v->errors());
 *   $data = $v->validated();   // only the declared, cleaned fields
 */

declare(strict_types=1);

final class Validator
{
    private array $errors = [];
    private array $clean  = [];

    private function __construct(private array $data, private array $rules, private array $labels = [])
    {
    }

    public static function make(array $data, array $rules, array $labels = []): self
    {
        $v = new self($data, $rules, $labels);
        $v->run();
        return $v;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /** First error per field. */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        return $this->errors ? reset($this->errors) : null;
    }

    /** Cleaned values for declared fields only. */
    public function validated(): array
    {
        return $this->clean;
    }

    private function label(string $field): string
    {
        return $this->labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $rules = is_array($ruleString) ? $ruleString : explode('|', $ruleString);
            $value = $this->data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }

            $isEmpty  = $value === null || $value === '' || $value === [];
            $nullable = in_array('nullable', $rules, true);

            if ($isEmpty) {
                if (in_array('required', $rules, true)) {
                    $this->errors[$field] = $this->label($field) . ' is required.';
                } elseif (in_array('boolean', $rules, true)) {
                    $this->clean[$field] = 0;   // unchecked checkbox
                } else {
                    $this->clean[$field] = $nullable ? null : ($value ?? null);
                }
                continue;
            }

            foreach ($rules as $rule) {
                [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);
                $error = $this->check($name, $param, $value, $field);
                if ($error !== null) {
                    $this->errors[$field] = $error;
                    continue 2;
                }
            }

            $this->clean[$field] = $this->cast($rules, $value);
        }
    }

    private function cast(array $rules, mixed $value): mixed
    {
        if (in_array('integer', $rules, true)) {
            return (int) $value;
        }
        if (in_array('numeric', $rules, true)) {
            return (float) $value;
        }
        if (in_array('boolean', $rules, true)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        if (in_array('email', $rules, true)) {
            return strtolower((string) $value);
        }
        if (in_array('phone', $rules, true)) {
            return preg_replace('/[^\d+]/', '', (string) $value);
        }
        if (in_array('html', $rules, true)) {
            return sanitize_html((string) $value);
        }
        if (in_array('array', $rules, true)) {
            return $value;
        }
        return is_string($value) ? clean_text($value) : $value;
    }

    private function check(string $rule, ?string $param, mixed $value, string $field): ?string
    {
        $label = $this->label($field);
        $str   = is_scalar($value) ? (string) $value : '';

        return match ($rule) {
            'required', 'nullable', 'html', 'string' => is_array($value) && $rule === 'string' ? "$label must be text." : null,

            'email'   => filter_var($str, FILTER_VALIDATE_EMAIL) ? null : 'Enter a valid email address.',
            'phone'   => preg_match('/^\+?[0-9][0-9\s\-]{8,15}$/', $str) ? null : 'Enter a valid phone number.',
            // http(s) URLs or on-site paths only — never javascript:, data: or protocol-relative //host
            'url'     => (preg_match('#^https?://#i', $str) && filter_var($str, FILTER_VALIDATE_URL)) || (str_starts_with($str, '/') && !str_starts_with($str, '//'))
                ? null : "$label must be a page on this site (starting with /) or a full https:// address.",
            'integer' => filter_var($str, FILTER_VALIDATE_INT) !== false ? null : "$label must be a whole number.",
            'numeric' => is_numeric($str) ? null : "$label must be a number.",
            'boolean' => in_array(strtolower($str), ['1', '0', 'true', 'false', 'on', 'off', 'yes', 'no'], true) || is_bool($value) ? null : "$label is invalid.",
            'array'   => is_array($value) ? null : "$label is invalid.",
            'slug'    => preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $str) ? null : "$label may only contain lowercase letters, numbers and hyphens.",

            'min'     => $this->sizeOf($value) >= (float) $param ? null : (is_numeric($value) ? "$label must be at least $param." : "$label must be at least $param characters."),
            'max'     => $this->sizeOf($value) <= (float) $param ? null : (is_numeric($value) && !is_string($value) ? "$label may not exceed $param." : "$label may not exceed $param characters."),
            'between' => (function () use ($param, $value, $label) {
                [$lo, $hi] = array_map('floatval', explode(',', (string) $param));
                $n = (float) $value;
                return $n >= $lo && $n <= $hi ? null : "$label must be between $lo and $hi.";
            })(),
            'in'      => in_array($str, explode(',', (string) $param), true) ? null : "$label is invalid.",

            'date'    => $this->isDate($str, 'Y-m-d') ? null : "$label must be a valid date.",
            'time'    => $this->isDate($str, 'H:i') || $this->isDate($str, 'H:i:s') ? null : "$label must be a valid time.",
            'after_or_today' => $this->isDate($str, 'Y-m-d') && $str >= date('Y-m-d') ? null : "$label cannot be in the past.",
            'before_days'    => $this->isDate($str, 'Y-m-d') && $str <= date('Y-m-d', strtotime('+' . (int) $param . ' days')) ? null : "$label must be within the next $param days.",

            'confirmed' => ($this->data[$field . '_confirmation'] ?? null) === $value ? null : "$label confirmation does not match.",
            'password'  => strlen($str) >= 8 && preg_match('/[A-Z]/', $str) && preg_match('/[a-z]/', $str) && preg_match('/\d/', $str)
                ? null : 'Password must be at least 8 characters with upper-case, lower-case and a number.',

            'unique'  => $this->isUnique($param, $str) ? null : "This $label is already in use.",
            'exists'  => $this->exists($param, $str) ? null : "Selected $label does not exist.",

            default   => throw new InvalidArgumentException("Unknown validation rule: $rule"),
        };
    }

    private function sizeOf(mixed $value): float
    {
        if (is_array($value)) {
            return count($value);
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        return (float) mb_strlen((string) $value);
    }

    private function isDate(string $value, string $format): bool
    {
        $d = DateTime::createFromFormat($format, $value);
        return $d !== false && $d->format($format) === $value;
    }

    /** unique:table,column[,ignoreId] */
    private function isUnique(?string $param, string $value): bool
    {
        [$table, $column, $ignore] = array_pad(explode(',', (string) $param), 3, null);
        assert_identifier((string) $table);
        assert_identifier((string) $column);
        $sql    = "SELECT COUNT(*) FROM `$table` WHERE `$column` = ?" . ($ignore ? ' AND id <> ?' : '');
        $params = $ignore ? [$value, (int) $ignore] : [$value];
        return (int) db_value($sql, $params) === 0;
    }

    /** exists:table,column */
    private function exists(?string $param, string $value): bool
    {
        [$table, $column] = array_pad(explode(',', (string) $param), 2, 'id');
        assert_identifier((string) $table);
        assert_identifier((string) $column);
        return (int) db_value("SELECT COUNT(*) FROM `$table` WHERE `$column` = ?", [$value]) > 0;
    }
}
