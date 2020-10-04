<?php

namespace Drupal\rest_feedback_endpoint\Plugin\rest\resource;

use Drupal\Component\Utility\Html;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Drupal\user\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a resource to email a bug report.
 *
 * @RestResource(
 *   id = "rest_feedback_endpoint_submit_issue",
 *   label = @Translation("Submit an issue"),
 *   uri_paths = {
 *    "canonical" = "/submit-issue",
 *    "create" = "/submit-issue"
 *   }
 * )
 */
class SubmitIssue extends ResourceBase {

  /**
   * A curent user instance.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs a Drupal\rest\Plugin\ResourceBase object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param array $serializer_formats
   *   The available serialization formats.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user instance.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, array $serializer_formats, LoggerInterface $logger, AccountProxyInterface $current_user) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('rest_feedback_endpoint'),
      $container->get('current_user')
    );
  }

  /**
   * Responds to POST requests.
   *
   * Returns a list of bundles for specified entity.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws exception expected.
   */
  public function post($data) {
    // Permission to access this endpoint is determined via
    // Drupal permissioning, at /admin/people/permissions#module-rest.
    $response_status['status'] = FALSE;
    \Drupal::logger('rest_feedback_endpoint')->notice(serialize($data));
    $config = \Drupal::config('rest_feedback_endpoint.settings');
    if (!$config->get('on')) {
      return new ResourceResponse($response_status);
    }
    if (!empty($data['title']) && !empty($data['description'])) {
      $roles = $this->currentUser->getRoles();
      $user = User::load($this->currentUser->id());
      $name = $user->get('field_full_name');
      $name = $name[0]['value'] ?? $this->currentUser->getUsername();
      $reported_roles = array_diff($roles, ['authenticated', 'administrator']);

      $mailManager = \Drupal::service('plugin.manager.mail');
      $module = 'rest_feedback_endpoint';
      $key = 'rest_feedback_endpoint';
      $to = $config->get('notification_email');
      $params['message'] = 'The user ' . $name . ' has reported an issue with the Crow web interface.' . PHP_EOL . PHP_EOL;
      $params['message'] .= 'SOURCE PAGE: ' . $data['url'] . PHP_EOL . PHP_EOL;
      $params['message'] .= 'DESCRIPTION: ' . Html::escape($data['description']) . PHP_EOL . PHP_EOL;
      $params['message'] .= 'USER ACCESS LEVEL: ' . implode(', ', $reported_roles) . PHP_EOL . PHP_EOL;
      $params['message'] .= 'TIMESTAMP: ' . date('F j, Y g:ia', time()) . PHP_EOL . PHP_EOL;
      $params['message'] .= 'PLATFORM/DEVICE: ' . $data['user_agent'] . PHP_EOL . PHP_EOL;
      if ($data['contact']) {
        $params['message'] .= 'CONTACT USER WITH UPDATES ABOUT THE ISSUE: yes (' . $this->currentUser->getEmail() . ')' . PHP_EOL . PHP_EOL;
      }
      else {
        $params['message'] .= 'CONTACT USER WITH UPDATES ABOUT THE ISSUE: no' . PHP_EOL . PHP_EOL;
      }
      $params['title'] = Html::escape($data['title']);
      $langcode = \Drupal::currentUser()->getPreferredLangcode();
      $send = TRUE;
      $response_status['status'] = $mailManager->mail($module, $key, $to, $langcode, $params, NULL, $send);
    }
    $response = new ResourceResponse($response_status);
    return $response;
  }

}
