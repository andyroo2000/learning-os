<?php

namespace App\Support\RateLimiting;

use App\Domain\Auth\Support\AuthAccountRateLimiter;
use App\Domain\Auth\Support\AuthEmailRateLimiter;
use App\Domain\Auth\Support\ConvoLabAccountSecurityRateLimiter;
use App\Domain\Auth\Support\ConvoLabOAuthRateLimiter;
use App\Domain\Auth\Support\ConvoLabProfileRateLimiter;
use App\Domain\Auth\Support\ConvoLabVerificationRateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class AuthenticationRateLimiterRegistrar
{
    public static function register(): void
    {
        self::registerAuthEmailRateLimiters();
        self::registerConvoLabVerificationAndProfileRateLimiters();
        self::registerConvoLabAccountSecurityRateLimiters();
        self::registerConvoLabOAuthRateLimiters();
        self::registerAuthAccountRateLimiters();
    }

    private static function registerAuthEmailRateLimiters(): void
    {
        $authEmailRateLimiter = new AuthEmailRateLimiter;
        RateLimiter::for(AuthEmailRateLimiter::MOBILE_TOKENS, function (Request $request) use ($authEmailRateLimiter): Limit {
            return $authEmailRateLimiter->mobileTokens($request);
        });
        RateLimiter::for(AuthEmailRateLimiter::CONVOLAB_LOGINS, function (Request $request) use ($authEmailRateLimiter): Limit {
            return $authEmailRateLimiter->convoLabLogins($request);
        });
        RateLimiter::for(AuthEmailRateLimiter::CONVOLAB_SIGNUPS, function (Request $request) use ($authEmailRateLimiter): Limit {
            return $authEmailRateLimiter->convoLabSignups($request);
        });
        RateLimiter::for(AuthEmailRateLimiter::MOBILE_REGISTRATIONS, function (Request $request) use ($authEmailRateLimiter): Limit {
            return $authEmailRateLimiter->mobileRegistrations($request);
        });
        RateLimiter::for(AuthEmailRateLimiter::PASSWORD_RESET_LINKS, function (Request $request) use ($authEmailRateLimiter): array {
            return $authEmailRateLimiter->passwordResetLinks($request);
        });
        RateLimiter::for(AuthEmailRateLimiter::PASSWORD_RESET_TOKENS, function (Request $request) use ($authEmailRateLimiter): Limit {
            return $authEmailRateLimiter->passwordResetTokens($request);
        });
    }

    private static function registerConvoLabVerificationAndProfileRateLimiters(): void
    {
        $convoLabVerificationSendRateLimiter = ConvoLabVerificationRateLimiter::forSend();
        RateLimiter::for(ConvoLabVerificationRateLimiter::SEND, function (Request $request) use ($convoLabVerificationSendRateLimiter): array {
            return [
                $convoLabVerificationSendRateLimiter->limit($request),
                $convoLabVerificationSendRateLimiter->networkLimit($request),
            ];
        });
        $convoLabVerificationVerifyRateLimiter = ConvoLabVerificationRateLimiter::forVerify();
        RateLimiter::for(ConvoLabVerificationRateLimiter::VERIFY, function (Request $request) use ($convoLabVerificationVerifyRateLimiter): array {
            return [
                $convoLabVerificationVerifyRateLimiter->limit($request),
                $convoLabVerificationVerifyRateLimiter->networkLimit($request),
            ];
        });

        RateLimiter::for(ConvoLabProfileRateLimiter::NAME, fn (Request $request): array => [
            ConvoLabProfileRateLimiter::limit($request),
            ConvoLabProfileRateLimiter::networkLimit($request),
        ]);
    }

    private static function registerConvoLabAccountSecurityRateLimiters(): void
    {
        foreach ([
            ConvoLabAccountSecurityRateLimiter::PASSWORD_UPDATE,
            ConvoLabAccountSecurityRateLimiter::ACCOUNT_DELETE,
        ] as $operation) {
            RateLimiter::for(
                $operation,
                fn (Request $request): array => ConvoLabAccountSecurityRateLimiter::limits($operation, $request),
            );
        }
    }

    private static function registerConvoLabOAuthRateLimiters(): void
    {
        RateLimiter::for(
            ConvoLabOAuthRateLimiter::RESOLVE,
            fn (Request $request): array => ConvoLabOAuthRateLimiter::resolve($request),
        );
        foreach ([
            ConvoLabOAuthRateLimiter::BROWSER_START,
            ConvoLabOAuthRateLimiter::BROWSER_CALLBACK,
        ] as $operation) {
            RateLimiter::for(
                $operation,
                fn (Request $request): array => ConvoLabOAuthRateLimiter::browser(
                    $operation,
                    $request,
                ),
            );
        }
        RateLimiter::for(
            ConvoLabOAuthRateLimiter::BROWSER_CLAIM,
            fn (Request $request): array => ConvoLabOAuthRateLimiter::browserClaim($request),
        );
        foreach ([ConvoLabOAuthRateLimiter::CLAIM, ConvoLabOAuthRateLimiter::DISCONNECT] as $operation) {
            RateLimiter::for(
                $operation,
                fn (Request $request): array => ConvoLabOAuthRateLimiter::authenticated($operation, $request),
            );
        }
    }

    private static function registerAuthAccountRateLimiters(): void
    {
        $accountProfileUpdateRateLimiter = AuthAccountRateLimiter::forProfileUpdate();
        RateLimiter::for(AuthAccountRateLimiter::PROFILE_UPDATE, function (Request $request) use ($accountProfileUpdateRateLimiter): Limit {
            return $accountProfileUpdateRateLimiter->limit($request);
        });

        $accountPasswordUpdateRateLimiter = AuthAccountRateLimiter::forPasswordUpdate();
        RateLimiter::for(AuthAccountRateLimiter::PASSWORD_UPDATE, function (Request $request) use ($accountPasswordUpdateRateLimiter): Limit {
            return $accountPasswordUpdateRateLimiter->limit($request);
        });

        $accountDeleteRateLimiter = AuthAccountRateLimiter::forAccountDelete();
        RateLimiter::for(AuthAccountRateLimiter::ACCOUNT_DELETE, function (Request $request) use ($accountDeleteRateLimiter): Limit {
            return $accountDeleteRateLimiter->limit($request);
        });

        $accountTokenRevokeRateLimiter = AuthAccountRateLimiter::forTokenRevoke();
        RateLimiter::for(AuthAccountRateLimiter::TOKEN_REVOKE, function (Request $request) use ($accountTokenRevokeRateLimiter): Limit {
            return $accountTokenRevokeRateLimiter->limit($request);
        });
    }
}
