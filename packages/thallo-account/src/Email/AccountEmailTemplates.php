<?php

declare(strict_types=1);

namespace Thallo\Account\Email;

use Glueful\Extensions\Contracts\Email\EmailTemplateDefinition;
use Glueful\Extensions\Contracts\Email\EmailTemplatePlaceholder;
use Thallo\Contracts\Account\AccountMailTemplates;

/**
 * The two mails a site's customers get, registered with the email-notification extension's
 * registry so they are edited apart from the admin's own verification and reset mails, in
 * Settings › Accounts. Re-registering the same keys under the same owner is allowed, so
 * per-request provider boots are safe.
 */
final class AccountEmailTemplates
{
    public const OWNER = 'thallo-account';

    /** @return list<EmailTemplateDefinition> */
    public static function definitions(): array
    {
        return [
            new EmailTemplateDefinition(
                key: AccountMailTemplates::VERIFICATION,
                label: 'Customer email verification',
                description: 'Sent to a visitor who registers on the site, with the code that confirms their address.',
                defaultSubject: 'Your {{app_name}} verification code',
                defaultBody: "{{> header}}\n"
                    . "<div class=\"message\">\n"
                    . "    <p>Enter this code on the site to finish creating your account:</p>\n"
                    . "    <div class=\"otp-container\"><div class=\"otp-code\">{{otp}}</div></div>\n"
                    . "    <p>The code expires in {{expiry_minutes}} minutes.</p>\n"
                    . "    <p>If you did not register, ignore this email.</p>\n"
                    . "</div>\n"
                    . "{{> footer}}",
                placeholders: [
                    new EmailTemplatePlaceholder('otp', 'The verification code', '123456'),
                    new EmailTemplatePlaceholder('expiry_minutes', 'Minutes until the code expires', '15'),
                    new EmailTemplatePlaceholder('app_name', 'The site name', 'Thallo'),
                ],
                owner: self::OWNER,
            ),
            new EmailTemplateDefinition(
                key: AccountMailTemplates::PASSWORD_RESET,
                label: 'Customer password reset',
                description: 'Sent to a customer who asks to reset their password, with the code that lets them.',
                defaultSubject: 'Reset your {{app_name}} password',
                defaultBody: "{{> header}}\n"
                    . "<div class=\"message\">\n"
                    . "    <p>Hello {{name}},</p>\n"
                    . "    <p>Enter this code on the site to choose a new password:</p>\n"
                    . "    <div class=\"otp-container\"><div class=\"otp-code\">{{otp}}</div></div>\n"
                    . "    <p>The code expires in {{expiry_minutes}} minutes.</p>\n"
                    . "    <p>If you did not ask for this, ignore this email. Your password stays as it is.</p>\n"
                    . "</div>\n"
                    . "{{> footer}}",
                placeholders: [
                    new EmailTemplatePlaceholder('name', 'The customer\'s first name', 'Ada'),
                    new EmailTemplatePlaceholder('otp', 'The reset code', '123456'),
                    new EmailTemplatePlaceholder('expiry_minutes', 'Minutes until the code expires', '15'),
                    new EmailTemplatePlaceholder('app_name', 'The site name', 'Thallo'),
                ],
                owner: self::OWNER,
            ),
        ];
    }
}
