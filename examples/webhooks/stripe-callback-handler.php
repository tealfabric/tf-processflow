<?php
/**
 * Stripe Webhook Callback Handler
 * 
 * System-level process step for receiving and routing Stripe webhook events.
 * 
 * This code snippet:
 * 1. Receives Stripe webhook events via HTTP POST
 * 2. Verifies webhook signature using Stripe endpoint secret
 * 3. Routes events to appropriate handlers based on event type
 * 4. Dispatches async process executions for implemented event types
 * 5. Logs all events for debugging and monitoring
 * 
 * Supported Event Types:
 * 
 * IMPLEMENTED (dispatches async processes):
 * - checkout.session.completed: Dispatches to proc_checkout-comple process (priority: normal)
 * - invoice.paid: Dispatches to proc_invoice-paid process (priority: low)
 * - customer.subscription.created: Dispatches to proc_subs-created process (priority: low)
 * 
 * CAPTURED (logged but not yet implemented):
 * - payment_intent.succeeded: Logged, no handler
 * - payment_method.attached: Logged, no handler
 * - invoice.payment_failed: Logged, no handler
 * - customer.subscription.deleted: Logged, no handler
 * - customer.created: Logged, no handler
 * 
 * Input:
 * - $process_input: Stripe webhook event JSON payload (parsed array)
 * - $raw_input: Raw webhook payload string (for signature verification)
 * - $http_headers: HTTP headers array containing 'Stripe-Signature' header
 * - $keystore: Keystore service with following keys:
 *   - 'stripe-private': Stripe secret API key
 *   - 'stripe-endpoint': Stripe webhook endpoint secret (for signature verification)
 *   - 'proc_checkout-comple': Process ID for checkout.session.completed handler
 *   - 'proc_invoice-paid': Process ID for invoice.paid handler
 *   - 'proc_subs-created': Process ID for customer.subscription.created handler
 * - $api: API service for making HTTP requests
 * - $app_url: Application base URL
 * 
 * Output:
 * - success: boolean - Whether webhook was processed successfully
 * - data: array - Stripe event data (parsed event object as array)
 * - message: string - Status message describing the result
 * 
 * Error Handling:
 * - Signature verification failures return success=false with error message
 * - Unknown event types return success=false with event type in message
 * - Failed async process dispatches are logged to $dlq array (dead letter queue)
 * 
 * Security:
 * - Webhook signature is verified using Stripe endpoint secret
 * - Invalid signatures are rejected and logged
 * - All events are logged for audit trail
 * 
 * Dependencies:
 * - Stripe PHP SDK (via vendor/autoload.php)
 * - ProcessFlow execution environment
 * - Keystore service for secure key storage
 */

require_once BASE_PATH.'/vendor/autoload.php';

$input_data = $process_input;
$result = [];

// ==============================
$stripeSecretKey = $keystore->get('stripe-private');
$endpoint_secret = $keystore->get('stripe-endpoint');
$endpoint_secret = $keystore->get('stripe-endpoint');

\Stripe\Stripe::setApiKey($stripeSecretKey);

$payload = $input_data;
error_log("STRIPE DEBUG: ". json_encode($payload));
error_log("STRIPE DEBUG: ". json_encode($http_headers));
$rawPayload = $raw_input;

// Initialize variables
$event = null;
$return_success = false;
$message = '';

if ($endpoint_secret) {
  // Only verify the event if there is an endpoint secret defined
  // Otherwise use the basic decoded event
  $sig_header = $http_headers['Stripe-Signature'] ?? '';

  try {
    error_log('STRIPE DEBUG: '. $sig_header);
    error_log('STRIPE DEBUG: '. $endpoint_secret);
    error_log('STRIPE DEBUG: '. json_encode($payload));
    
    $event = \Stripe\Webhook::constructEvent($rawPayload, $sig_header, $endpoint_secret);
    error_log("STRIPE DEBUG: ". json_encode($event));
    
  } catch(\Stripe\Exception\SignatureVerificationException $e) {
    // Invalid signature
    error_log('⚠️  Webhook error while validating signature.');
    $return_success = false;
    $message = 'Webhook error while validating signature: ' . $e->getMessage();
    // Set event to null to prevent errors below
    $event = null;
  } catch(\Exception $e) {
    // Other errors
    error_log('⚠️  Webhook error: ' . $e->getMessage());
    $return_success = false;
    $message = 'Webhook error: ' . $e->getMessage();
    $event = null;
  }
}

// Convert Stripe Event object to array if needed
if ($event instanceof \Stripe\Event) {
    // Stripe objects can be converted to array using json_encode/json_decode
    $eventArray = json_decode(json_encode($event), true);
    $event = $eventArray;
}

if (!$event) {
    // If event is null (e.g., signature verification failed), return error
    return [
        'success' => false,
        'data' => [],
        'message' => $message ?? 'Failed to process webhook event'
    ];
}

error_log("STRIPE DEBUG: ". json_encode($event));
// Handle the event
switch ($event['type']) {

    // CASES THAT NEEDS TO BE IMPLEMENTED
    case 'payment_intent.succeeded':
    $paymentIntent = $event['data']['object']; // contains a \Stripe\PaymentIntent
    // Then define and call a method to handle the successful payment intent.
    // handlePaymentIntentSucceeded($paymentIntent);
    error_log("STRIPE DEBUG: payment_intent.succeeded");
    $return_success = true;
    $message = 'Event payment_intent.succeeded captured successfully';
    break;
  case 'payment_method.attached':
    $paymentMethod = $event['data']['object']; // contains a \Stripe\PaymentMethod
    // Then define and call a method to handle the successful attachment of a PaymentMethod.
    // handlePaymentMethodAttached($paymentMethod);
    error_log("STRIPE DEBUG: payment_method.attached");
    $return_success = true;
    $message = 'Event payment_method.attached  captured successfully';
    break;
  case 'invoice.payment_failed':
    $paymentMethod = $event['data']['object']; // contains a \Stripe\PaymentMethod
    // Then define and call a method to handle the successful attachment of a PaymentMethod.
    // handlePaymentMethodAttached($paymentMethod);
    error_log("STRIPE DEBUG: invoice.payment_failed");
    $return_success = true;
    $message = 'Event invoice.payment_failed  captured successfully';
    break;
    case 'customer.subscription.deleted':
        $paymentMethod = $event['data']['object']; // contains a \Stripe\PaymentMethod
        // Then define and call a method to handle the successful attachment of a PaymentMethod.
        // handlePaymentMethodAttached($paymentMethod);
        error_log("STRIPE DEBUG: customer.subscription.deleted");
        $return_success = true;
        $message = 'customer.subscription.deleted';
        break;

        // CUSTOMER CREATED

case 'customer.created':
    $paymentMethod = $event['data']['object']; // contains a \Stripe\PaymentMethod
    // Then define and call a method to handle the successful attachment of a PaymentMethod.
    // handlePaymentMethodAttached($paymentMethod);
    error_log("STRIPE DEBUG: customer.created");
    $return_success = true;
    $message = 'customer.created';
    break;

    
// CASES THAT ARE IMPLEMENTED ALREADY
    case 'invoice.paid':

    $paymentMethod = $event['data']['object']; // contains a \Stripe\PaymentMethod
    // Then define and call a method to handle the successful attachment of a PaymentMethod.
    // handlePaymentMethodAttached($paymentMethod);
    error_log("STRIPE DEBUG: invoice.paid");
        // Call an async process
        $result = $api->post("{$app_url}/api/v1/processflow?action=execute-process", [
                'process_id' => $keystore->get('proc_invoice-paid'),
                'input_data' => $process_input,
                'async' => true,
                'options' => [
                    'priority' => 'low'
                ]
            ]);
        if (!$result['success']) {
            $dlq[] = [
                'process'=>$target,
                'job_id'=>$result['queue_id'],
                'error'=>$result['error'],
                'message' => ['Process execution failed for process: '.$target]
            ];
        }
    $return_success = true;
    $message = 'Event invoice.paid captured and dispatched successfully';
    break;

case 'customer.subscription.created':


    $paymentMethod = $event['data']['object']; // contains a \Stripe\PaymentMethod
    // Make an async call to the corresponging subprocess
    
        // Call an async process
        $result = $api->post("{$app_url}/api/v1/processflow?action=execute-process", [
                'process_id' => $keystore->get('proc_subs-created'),
                'input_data' => $process_input,
                'async' => true,
                'options' => [
                    'priority' => 'low'
                ]
            ]);
        if (!$result['success']) {
            $dlq[] = [
                'process'=>$target,
                'job_id'=>$result['queue_id'],
                'error'=>$result['error'],
                'message' => ['Process execution failed for process: '.$target]
            ];
        }
   
    error_log("STRIPE DEBUG: customer.subscription.created");
    $return_success = true;
    $message = 'Event customer.subscription.created captured and dispatched successfully';
    break;

    case 'checkout.session.completed':
        $paymentMethod = $event['data']['object']; // contains a \Stripe\CheckoutSession
        // Call an async process to handle checkout session completion
        $result = $api->post("{$app_url}/api/v1/processflow?action=execute-process", [
                'process_id' => $keystore->get('proc_checkout-comple'),
                'input_data' => $process_input,
                'async' => true,
                'options' => [
                    'priority' => 'normal'
                ]
            ]);
        if (!$result['success']) {
            $dlq[] = [
                'process'=>$target,
                'job_id'=>$result['queue_id'],
                'error'=>$result['error'],
                'message' => ['Process execution failed for process: '.$target]
            ];
        }
        error_log("STRIPE DEBUG: checkout.session.completed");
        $return_success = true;
        $message = 'Event checkout.session.completed captured and dispatched successfully';
        break;


    case 'invoice.upcoming':
    $paymentMethod = $event['data']['object']; // contains a \Stripe\CheckoutSession
    // Call an async process to handle checkout session completion
    $result = $api->post("{$app_url}/api/v1/processflow?action=execute-process", [
            'process_id' => $keystore->get('proc_upcoming_inv'),
            'input_data' => $process_input,
            'async' => true,
            'options' => [
                'priority' => 'normal'
            ]
        ]);
    if (!$result['success']) {
        $dlq[] = [
            'process'=>$target,
            'job_id'=>$result['queue_id'],
            'error'=>$result['error'],
            'message' => ['Process execution failed for process: '.$target]
        ];
    }
    error_log("STRIPE DEBUG: invoice.upcoming");
    $return_success = true;
    $message = 'Event invoice.upcoming captured and dispatched successfully';
    break;

    case 'customer.deleted':
            $paymentMethod = $event['data']['object']; // contains a \Stripe\CheckoutSession
            // Call an async process to handle checkout session completion
            $result = $api->post("{$app_url}/api/v1/processflow?action=execute-process", [
                    'process_id' => $keystore->get('proc_cust_deleted'),
                    'input_data' => $process_input,
                    'async' => true,
                    'options' => [
                        'priority' => 'normal'
                    ]
                ]);
            if (!$result['success']) {
                $dlq[] = [
                    'process'=>$target,
                    'job_id'=>$result['queue_id'],
                    'error'=>$result['error'],
                    'message' => ['Process execution failed for process: '.$target]
                ];
            }
        error_log("STRIPE DEBUG: customer.deleted");
        $return_success = true;
        $message = 'Event customer.deleted captured and dispatched successfully';
    break;

  default:
    // Unexpected event type
    $return_success = false;
    $message = 'Received unknown event type: ' . ($event['type'] ?? 'unknown');
    error_log('Received unknown event type: ' . ($event['type'] ?? 'unknown'));
}

// ================================

// If dlq is not empty, append it to the result array
if (!empty($dlq)) {
    $result['dlq'] = $dlq;
}
// Add the original event to the result array
$result['event'] = $event;
// Return array format (REQUIRED)
return [
    'success' => $return_success,           // Required: boolean
    'data' => $result,          // Required: your processed data (now an array)
    'message' => $message  // Optional: status message
];