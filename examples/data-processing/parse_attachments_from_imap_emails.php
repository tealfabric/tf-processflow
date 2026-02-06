<?php
/**
 * Parse attachments from IMAP emails using zbateson/mail-mime-parser
 * 
 * Requires: composer require zbateson/mail-mime-parser
 * 
 * Note: Use fully qualified class names (no 'use' statements) in ProcessFlow code snippets
 */

// Debug: Log full process_input structure
$log_message('[DEBUG] parse_attachments - process_input keys: ' . json_encode(array_keys($process_input ?? [])));
$log_message('[DEBUG] parse_attachments - process_input[result] keys: ' . json_encode(array_keys($process_input['result'] ?? [])));

$emailData = $process_input['result']['emails'] ?? null;

$log_message('[DEBUG] parse_attachments - emailData type: ' . gettype($emailData));
$log_message('[DEBUG] parse_attachments - emailData keys: ' . json_encode(array_keys($emailData ?? [])));

if (!$emailData) {
    $log_message('[DEBUG] parse_attachments - No email_data found in process_input[result]');
    return ['success' => false, 'error' => ['code' => 'EMAIL_DATA_NOT_FOUND', 'message' => 'Email data not found'], 'data' => null];
}

$emails = $emailData['emails'] ?? [];
$log_message('[DEBUG] parse_attachments - emails count: ' . count($emails));
$log_message('[DEBUG] parse_attachments - emails array sample: ' . json_encode(array_slice($emails, 0, 1)));

$processedEmails = [];
$parser = new \ZBateson\MailMimeParser\MailMimeParser();

foreach ($emails as $email) {
    $msgNo = $email['message_number'] ?? null;
    $body = $email['body'] ?? '';
    
    if (empty($body)) {
        $processedEmails[] = [
            'message_number' => $msgNo,
            'subject' => $email['subject'] ?? '',
            'from' => $email['from'] ?? '',
            'parsed' => false,
            'reason' => 'No body content'
        ];
        continue;
    }
    
    try {
        // Parse the email using mail-mime-parser
        $message = $parser->parse($body, false);
        
        // Extract text content
        $textPlain = $message->getTextContent() ?? '';
        $textHtml = $message->getHtmlContent() ?? '';
        
        // Extract attachments
        $attachments = [];
        foreach ($message->getAllAttachmentParts() as $attachment) {
            $filename = $attachment->getFilename() ?? 'attachment';
            $contentType = $attachment->getContentType() ?? 'application/octet-stream';
            $content = $attachment->getContent();
            
            // Sanitize filename
            $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($filename)) ?: 'attachment';
            
            $attachments[] = [
                'filename' => $filename,
                'content_type' => $contentType,
                'content' => $content,
                'size' => strlen($content)
            ];
        }
        
        // Save attachments to disk using tenant-scoped file operations
        // Note: In ProcessFlow code snippets, file functions are available as closure variables
        // ($file_put_contents, $mkdir, $chmod, $file_exists, $is_dir) and are automatically
        // scoped to storage/tenantdata/<tenant_id>/
        $savedAttachments = [];
        if (!empty($attachments)) {
            $attDir = 'emails/' . ($msgNo ?? 'unknown') . '/attachments';
            if (!$is_dir($attDir)) {
                $mkdir($attDir, 0700, true);
            }
            
            foreach ($attachments as $idx => $att) {
                $fn = $att['filename'] ?: 'attachment_' . $idx;
                $content = $att['content'] ?? '';
                
                if (empty($content)) {
                    continue;
                }
                
                $fp = $attDir . '/' . $fn;
                $cnt = 1;
                $pi = pathinfo($fn);  // pathinfo() is a native PHP function (not sandboxed)
                $bn = $pi['filename'];
                $ext = $pi['extension'] ?? '';
                
                // Handle duplicate filenames
                while ($file_exists($fp)) {
                    $fp = $attDir . '/' . $bn . '_' . $cnt . ($ext ? '.' . $ext : '');
                    $cnt++;
                }
                
                $bytes = $file_put_contents($fp, $content);
                if ($bytes !== false) {
                    $chmod($fp, 0600);
                    $savedAttachments[] = [
                        'original_filename' => $fn,
                        'saved_filename' => basename($fp),
                        'relative_path' => $fp,
                        'size' => $bytes,
                        'content_type' => $att['content_type'],
                        'saved' => true
                    ];
                }
            }
        }
        
        $processedEmails[] = [
            'message_number' => $msgNo,
            'uid' => $email['uid'] ?? null,
            'subject' => $email['subject'] ?? $message->getHeaderValue('subject') ?? '',
            'from' => $email['from'] ?? $message->getHeaderValue('from') ?? '',
            'to' => $email['to'] ?? $message->getHeaderValue('to') ?? '',
            'date' => $email['date'] ?? $message->getHeaderValue('date') ?? null,
            'body_plain' => $textPlain,
            'body_html' => $textHtml,
            'has_attachments' => !empty($attachments),
            'attachment_count' => count($attachments),
            'attachments' => $savedAttachments,
            'parsed' => true
        ];
        
    } catch (Exception $e) {
        $processedEmails[] = [
            'message_number' => $msgNo,
            'subject' => $email['subject'] ?? '',
            'from' => $email['from'] ?? '',
            'parsed' => false,
            'reason' => 'Parse error: ' . $e->getMessage()
        ];
    }
}

return [
    'success' => true,
    'data' => [
        'emails_processed' => count($processedEmails),
        'emails' => $processedEmails
    ],
    'message' => 'Processed ' . count($processedEmails) . ' email(s) with attachment extraction'
];
