<?php

namespace Drupal\as_webhook_update\Service;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\key\KeyRepositoryInterface;

/**
 * Service for sending webhook HTTP requests.
 *
 * Encapsulates all HTTP/cURL functionality for sending webhook notifications
 * to remote systems. Handles authentication, timeouts, and logging.
 * Uses the Key module for secure token storage.
 */
class HttpClientService {

  /**
   * The key repository service.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected $keyRepository;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs an HttpClientService object.
   *
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository service.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   */
  public function __construct(KeyRepositoryInterface $key_repository, LoggerChannelInterface $logger) {
    $this->keyRepository = $key_repository;
    $this->logger = $logger;
  }

  /**
   * Sends a POST request to a webhook URL.
   *
   * @param string $data
   *   JSON-encoded data to send.
   * @param string $url
   *   The webhook URL to send the request to.
   *
   * @return array
   *   An array containing:
   *   - 'success': Boolean indicating if the request was successful.
   *   - 'http_code': The HTTP response code.
   *   - 'result': The response body (if any).
   */
  public function post(string $data, string $url): array {
    // Get auth token from Key module.
    $key = $this->keyRepository->getKey('as_webhook_update_token');
    $auth_token = $key ? $key->getKeyValue() : '';

    if (empty($auth_token)) {
      $this->logger->error('Webhook authorization token is not configured. Please configure it at /admin/config/services/webhook-update');
      return [
        'success' => FALSE,
        'http_code' => 0,
        'result' => 'Authorization token not configured',
      ];
    }

    // Initialize cURL.
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Authorization: ' . $auth_token,
      'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

    // Execute the request.
    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Log the transaction.
    $this->logger->info('cURL request to @url, HTTP code @code, result: @result', [
      '@url' => $url,
      '@code' => $http_code,
      '@result' => $result ?: 'No response body',
    ]);

    return [
      'success' => $http_code >= 200 && $http_code < 300,
      'http_code' => $http_code,
      'result' => $result,
    ];
  }

  /**
   * Sends a POST request and logs the full data payload.
   *
   * This is the legacy method that matches the original behavior
   * of as_webhook_update_getcurl().
   *
   * @param string $data
   *   JSON-encoded data to send.
   * @param string $url
   *   The webhook URL to send the request to.
   * @param string $host
   *   The originating host (for logging purposes).
   *
   * @return int
   *   The HTTP response code.
   */
  public function postWithFullLogging(string $data, string $url, string $host): int {
    $response = $this->post($data, $url);

    // Log the full transaction with data payload (legacy format).
    $this->logger->info("curl request was made by @host to @url, result @result, http code @code\ndata:\n@data", [
      '@host' => $host,
      '@url' => $url,
      '@result' => json_encode($response['result'], JSON_PRETTY_PRINT),
      '@code' => json_encode($response['http_code'], JSON_PRETTY_PRINT),
      '@data' => $data,
    ]);

    return $response['http_code'];
  }

}
