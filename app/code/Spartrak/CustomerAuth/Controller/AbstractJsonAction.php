<?php
/**
 * Copyright © ElAssal for Trading & Supply. All rights reserved.
 */

declare(strict_types=1);

namespace Spartrak\CustomerAuth\Controller;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\State\UserLockedException;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Spartrak\CustomerAuth\Exception\FieldValidationException;
use Spartrak\CustomerAuth\Exception\InvalidPhoneNumberException;
use Spartrak\CustomerAuth\Exception\OtpVerificationException;
use Spartrak\CustomerAuth\Exception\RateLimitExceededException;
use Spartrak\CustomerAuth\Model\Phone\Normalizer;

/**
 * Shared plumbing for this module's JSON endpoints.
 *
 * All five endpoints are POST-only and CSRF-protected. Note what is deliberately
 * absent: CsrfAwareActionInterface. Implementing it is how a controller opts OUT
 * of Magento's automatic form-key validation, and these are exactly the endpoints
 * that must never do that — without it, any page on the internet could POST a
 * password reset on a logged-in shopper's behalf. The storefront therefore has to
 * send form_key with every request, which is why responses carry a refreshed one.
 *
 * Error handling policy: known, expected failures become a 4xx with a
 * shopper-facing message; everything else becomes a generic 500 with the detail
 * confined to the log. An exception message from deep inside Magento can name a
 * class, a table or an email address, and none of that belongs in a response
 * body on an authentication endpoint.
 */
abstract class AbstractJsonAction extends Action
{
    protected const HTTP_BAD_REQUEST = 400;
    protected const HTTP_UNAUTHORIZED = 401;
    protected const HTTP_FORBIDDEN = 403;
    protected const HTTP_CONFLICT = 409;
    protected const HTTP_TOO_MANY_REQUESTS = 429;
    protected const HTTP_INTERNAL_ERROR = 500;

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        protected readonly Normalizer $phoneNormalizer,
        protected readonly StoreManagerInterface $storeManager,
        protected readonly LoggerInterface $logger,
        protected readonly RemoteAddress $remoteAddress
    ) {
        parent::__construct($context);
    }

    /**
     * Handle the request, or throw. Wrapped by execute() below.
     *
     * @return array<string, mixed> Payload merged into the success envelope.
     */
    abstract protected function handle(): array;

    /**
     * @inheritDoc
     */
    public function execute()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->error(
                __('Invalid request method.'),
                self::HTTP_BAD_REQUEST
            );
        }

        try {
            return $this->success($this->handle());
        } catch (RateLimitExceededException $e) {
            return $this->error($e->getMessage(), self::HTTP_TOO_MANY_REQUESTS, [
                'retry_after' => $e->getRetryAfterSeconds(),
            ]);
        } catch (OtpVerificationException $e) {
            $payload = [];

            if ($e->getAttemptsRemaining() !== null) {
                $payload['attempts_remaining'] = $e->getAttemptsRemaining();
            }

            return $this->error($e->getMessage(), self::HTTP_FORBIDDEN, $payload);
        } catch (InvalidPhoneNumberException $e) {
            return $this->error($e->getMessage(), self::HTTP_BAD_REQUEST, ['field' => 'phone']);
        } catch (UserLockedException $e) {
            return $this->error($e->getMessage(), self::HTTP_FORBIDDEN);
        } catch (FieldValidationException $e) {
            // MUST stay above the LocalizedException arm below — this extends it,
            // and PHP takes the first matching catch, so ordering is what keeps
            // the `field` hint from being swallowed by the generic handler.
            return $this->error($e->getMessage(), self::HTTP_BAD_REQUEST, ['field' => $e->getField()]);
        } catch (LocalizedException $e) {
            // LocalizedException is Magento's contract for "this message was
            // written to be shown to a user", so it is safe to surface.
            return $this->error($e->getMessage(), self::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->critical(
                'Spartrak_CustomerAuth: unhandled error in ' . static::class . ': ' . $e->getMessage(),
                ['exception' => $e]
            );

            return $this->error(
                __('Something went wrong. Please try again.'),
                self::HTTP_INTERNAL_ERROR
            );
        }
    }

    /**
     * Read a scalar POST field as a trimmed string.
     *
     * Guards against array injection: `phone[]=x` makes getPostValue() return an
     * array, and casting an array to string is a fatal in PHP 8. Anything
     * non-scalar becomes an empty string and fails the caller's own validation.
     */
    protected function getPostString(string $key): string
    {
        $value = $this->getRequest()->getPostValue($key);

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * Normalize the posted phone number, or throw.
     *
     * @throws InvalidPhoneNumberException
     */
    protected function getPostedPhone(string $key = 'phone'): string
    {
        return $this->phoneNormalizer->normalize($this->getPostString($key));
    }

    protected function getStoreId(): int
    {
        return (int) $this->storeManager->getStore()->getId();
    }

    /**
     * The client IP, for the per-IP send quota.
     *
     * Goes through Magento's RemoteAddress helper rather than reading $_SERVER or
     * an X-Forwarded-For header directly, so the install's own trusted-proxy
     * configuration decides which address to believe. Trusting a forwarded header
     * unconditionally would make the per-IP quota worthless — the header is
     * attacker-controlled, so a bot would simply send a new value per request.
     *
     * DEPLOYMENT NOTE, and this one bites in both directions: behind a CDN or
     * load balancer whose proxy headers are NOT configured as trusted, every
     * request appears to originate from the balancer, so the per-IP quota
     * throttles the entire store as if it were one client. Confirm
     * `Magento\Framework\HTTP\PhpEnvironment\RemoteAddress::$trustedProxies` (or
     * the equivalent `remote_addresses` setting) reflects the real edge before
     * relying on this limit in production. The per-phone limits are unaffected
     * either way, which is why they carry the primary defence.
     */
    protected function getClientIp(): ?string
    {
        $ip = $this->remoteAddress->getRemoteAddress();

        if (!is_string($ip) || $ip === '') {
            return null;
        }

        // Column is varchar(45) — long enough for any IPv6 literal, but truncate
        // rather than let an oversized value break the insert.
        return substr($ip, 0, 45);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function success(array $payload = []): JsonResult
    {
        return $this->jsonFactory->create()->setData(
            array_merge(['success' => true], $payload)
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function error(mixed $message, int $httpStatus, array $payload = []): JsonResult
    {
        $result = $this->jsonFactory->create();
        $result->setHttpResponseCode($httpStatus);

        $response = $this->getResponse();

        if ($httpStatus === self::HTTP_TOO_MANY_REQUESTS
            && isset($payload['retry_after'])
            && $response instanceof HttpResponse
        ) {
            // Real Retry-After header as well as the JSON field: it is what
            // proxies, monitoring and any future native client will read.
            $response->setHeader('Retry-After', (string) (int) $payload['retry_after'], true);
        }

        return $result->setData(
            array_merge(
                [
                    'success' => false,
                    'message' => (string) $message,
                ],
                $payload
            )
        );
    }
}
