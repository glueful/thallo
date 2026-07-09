<?php

declare(strict_types=1);

namespace App\Tests\Integration\Forms;

use App\Content\Forms\FormFieldDerivation;
use PHPUnit\Framework\TestCase;

final class FormFieldDerivationTest extends TestCase
{
    public function testCoreContactFieldsAlwaysPresent(): void
    {
        $fields = FormFieldDerivation::derive([]);
        self::assertSame(['name', 'email', 'message'], array_map(fn ($f) => $f->key, $fields));
        self::assertSame('email', $fields[1]->type);
        self::assertTrue($fields[1]->required);
        self::assertSame('textarea', $fields[2]->type);
    }

    public function testTogglesAddOptionalFields(): void
    {
        $fields = FormFieldDerivation::derive([
            'include_subject' => true, 'include_phone' => true, 'include_consent' => true,
            'consent_text' => 'I agree',
        ]);
        $keys = array_map(fn ($f) => $f->key, $fields);
        self::assertSame(['name', 'subject', 'email', 'phone', 'message', 'consent'], $keys);
        $consent = end($fields);
        self::assertSame('checkbox', $consent->type);
        self::assertSame('I agree', $consent->label);
        self::assertTrue($consent->required);
    }

    public function testLabelOverridesApply(): void
    {
        $fields = FormFieldDerivation::derive(['name_label' => 'Your name', 'email_placeholder' => 'you@co']);
        self::assertSame('Your name', $fields[0]->label);
        self::assertSame('you@co', $fields[1]->placeholder);
    }
}
