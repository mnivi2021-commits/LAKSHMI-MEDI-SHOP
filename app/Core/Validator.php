<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal field validator. Collects messages per field:
 *   $v = new Validator();
 *   $v->required('name', $name, 'Name')->maxLength('name', $name, 100, 'Name');
 *   if ($v->fails()) { ... $v->errors() ... }
 */
final class Validator
{
    /** @var array<string, string> field => first error */
    private array $errors = [];

    public function required(string $field, string $value, string $label): self
    {
        if (trim($value) === '') {
            $this->add($field, "{$label} is required.");
        }
        return $this;
    }

    public function maxLength(string $field, string $value, int $max, string $label): self
    {
        if (mb_strlen($value) > $max) {
            $this->add($field, "{$label} must be at most {$max} characters.");
        }
        return $this;
    }

    public function email(string $field, string $value, string $label = 'Email'): self
    {
        if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->add($field, "{$label} is not a valid email address.");
        }
        return $this;
    }

    public function pattern(string $field, string $value, string $regex, string $message): self
    {
        if ($value !== '' && !preg_match($regex, $value)) {
            $this->add($field, $message);
        }
        return $this;
    }

    /** Indian mobile: 10 digits starting 6-9, optional +91 / 0 prefix. */
    public function mobile(string $field, string $value, string $label = 'Mobile'): self
    {
        if ($value !== '' && !preg_match('/^(\+91[\s-]?|0)?[6-9]\d{9}$/', preg_replace('/[\s-]/', '', $value))) {
            $this->add($field, "{$label} must be a valid 10-digit mobile number.");
        }
        return $this;
    }

    /** @param list<string|int> $allowed */
    public function in(string $field, string|int $value, array $allowed, string $label): self
    {
        if (!in_array($value, $allowed, true)) {
            $this->add($field, "Choose a valid {$label}.");
        }
        return $this;
    }

    public function add(string $field, string $message): self
    {
        $this->errors[$field] ??= $message;
        return $this;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
