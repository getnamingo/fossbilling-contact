<?php

/**
 * FOSSBilling.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license   Apache-2.0
 *
 * Copyright FOSSBilling 2022
 * This software may contain code previously used in the BoxBilling project.
 * Copyright BoxBilling, Inc 2011-2021
 *
 * This source file is subject to the Apache-2.0 License that is bundled
 * with this source code in the file LICENSE
 */

namespace Box\Mod\Contact\Controller;

class Client implements \FOSSBilling\InjectionAwareInterface
{
    protected $di;

    public function setDi(\Pimple\Container|null $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    /**
     * Methods maps client areas urls to corresponding methods
     * Always use your module prefix to avoid conflicts with other modules
     * in future.
     *
     * @param \Box_App $app - returned by reference
     */
    public function register(\Box_App &$app): void
    {
        $app->get('/contact', 'get_index', [], static::class);
        $app->post('/contact', 'get_index', [], static::class);
    }

    public function get_index(\Box_App $app)
    {
        // Initialize variables
        $error = '';
        $success = '';

        // Capture GET parameter and sanitize the domain
        $domain = filter_input(INPUT_GET, 'domain', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        
        // Process form submission if it's a POST request
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Capture and sanitize form data
            $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
            $message = filter_input(INPUT_POST, 'message', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

            // Validate form data
            if ($name && $email && $message) {
                // Define sender and recipient
                $parts = explode('.', $domain);
                $sld = '';
                $tld = '';

                if (count($parts) >= 3) {
                    $tld = implode('.', array_slice($parts, -2));
                    $sld = $parts[count($parts) - 3];
                } elseif (count($parts) === 2) {
                    $sld = $parts[0];
                    $tld = $parts[1];
                } else {
                    $error = "Error: Invalid domain format.";
                }

                if ($sld && $tld) {
                    $contact = $this->di['db']->getRow(
                        'SELECT * FROM service_domain WHERE sld = :sld AND tld = :tld',
                        ['sld' => $sld, 'tld' => '.' . $tld]
                    );
                } else {
                    $error = "Error: Unable to extract SLD and TLD from the domain.";
                }

                $sender = [
                    'email' => $email,
                    'name' => $name,
                ];
                $recipient = [
                    'email' => $contact['contact_email'],
                    'name' => $contact['contact_first_name'] . ' ' . $contact['contact_last_name'],
                ];

                // Get email settings
                $mod = $this->di['mod']('email');
                $settings = $mod->getConfig();
                $logEnabled = isset($settings['log_enabled']) && $settings['log_enabled'];
                $transport = $settings['mailer'] ?? 'sendmail';

                // Prepare the email content and transport
                $mail = new \FOSSBilling\Mail($sender, $recipient, "Contact Domain Registrant: " . $domain, $message, $transport, $settings['custom_dsn'] ?? null);

                try {
                    // Attempt to send the email
                    $mail->send($settings);
                        
                    // Log activity if enabled
                    if ($logEnabled) {
                        $activityService = $this->di['mod_service']('activity');
                        $activityService->logEmail("Contact Domain Registrant: " . $domain, null, $email, $contact['contact_email'], $message);
                    }
                    $success = 'Your message has been sent successfully.';
                } catch (\Exception $e) {
                    $error = 'Failed to send the message. Please try again later.';
                    $this->di['logger']->setChannel('email')->err($e->getMessage());
                }
            } else {
                $error = 'Please fill in all fields with valid information.';
            }
        } else {
            // Existing GET logic for domain validation
            if ($domain) {
                $parts = explode('.', $domain);
                $sld = '';
                $tld = '';

                if (count($parts) >= 3) {
                    $tld = implode('.', array_slice($parts, -2));
                    $sld = $parts[count($parts) - 3];
                } elseif (count($parts) === 2) {
                    $sld = $parts[0];
                    $tld = $parts[1];
                } else {
                    $error = "Error: Invalid domain format.";
                }

                if ($sld && $tld) {
                    $domainExists = $this->di['db']->getAll(
                        'SELECT * FROM service_domain WHERE sld = :sld AND tld = :tld',
                        ['sld' => $sld, 'tld' => '.' . $tld]
                    );

                    if (!$domainExists) {
                        $error = "Error: The specified domain does not exist.";
                    }
                } else {
                    $error = "Error: Unable to extract SLD and TLD from the domain.";
                }
            } else {
                $error = "Error: You must specify a domain.";
            }
        }

        return $app->render('mod_contact_index', [
            'domain' => $domain,
            'error' => $error,
            'success' => $success,
        ]);
    }

}