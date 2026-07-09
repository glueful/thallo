<?php

declare(strict_types=1);

namespace App\Tests\Integration\Forms;

use App\Content\Forms\FieldDef;
use App\Content\Forms\FormValueNormalizer;
use PHPUnit\Framework\TestCase;

final class FormValueNormalizerTest extends TestCase
{
    /** @return list<FieldDef> */
    private function fields(): array
    {
        return [
            new FieldDef('email', 'Email', 'email', true, null, null, []),
            new FieldDef('message', 'Message', 'textarea', true, null, null, []),
            new FieldDef('consent', 'I agree', 'checkbox', true, null, null, []),
        ];
    }

    public function testValidatesAndNormalizes(): void
    {
        $out = FormValueNormalizer::normalize($this->fields(), [
            'email' => '  Owner@Site.test ', 'message' => 'hi', 'consent' => 'on',
            'evil' => 'ignored', // unknown key dropped
        ]);
        self::assertSame([], $out['errors']);
        self::assertSame('Owner@Site.test', $out['values']['email']); // trimmed
        self::assertTrue($out['values']['consent']);                   // checkbox → bool
        self::assertArrayNotHasKey('evil', $out['values']);            // not against sealed fields
    }

    public function testRequiredAndFormatErrors(): void
    {
        $out = FormValueNormalizer::normalize($this->fields(), ['email' => 'nope', 'message' => '']);
        self::assertArrayHasKey('email', $out['errors']); // bad format
        self::assertArrayHasKey('message', $out['errors']); // required empty
        self::assertArrayHasKey('consent', $out['errors']); // required checkbox unchecked
    }

    public function testSelectMustMatchOptions(): void
    {
        $fields = [new FieldDef('topic', 'Topic', 'select', true, null, null, ['sales', 'support'])];
        $ok = FormValueNormalizer::normalize($fields, ['topic' => 'sales']);
        self::assertSame([], $ok['errors']);
        self::assertSame('sales', $ok['values']['topic']);

        $bad = FormValueNormalizer::normalize($fields, ['topic' => 'other']);
        self::assertArrayHasKey('topic', $bad['errors']);
    }
}
