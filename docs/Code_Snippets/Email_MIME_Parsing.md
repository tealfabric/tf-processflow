# Email MIME Parsing in ProcessFlow Code Snippets

This guide explains how to parse email messages and extract attachments using the `zbateson/mail-mime-parser` library in ProcessFlow code snippets.

## Overview

When processing emails from IMAP integrations, you often need to extract:
- Plain text body content
- HTML body content
- File attachments

The `zbateson/mail-mime-parser` library handles all the complexity of MIME parsing including:
- Multipart message structures (mixed, alternative, related)
- Nested multipart content
- Content-Transfer-Encoding (base64, quoted-printable, 7bit, 8bit)
- RFC 2047 encoded headers (international characters in filenames/subjects)
- Inline vs attachment disposition

## Prerequisites

The library must be installed in the TealFabric.io project:

```bash
composer require zbateson/mail-mime-parser
```

## Library Usage

### Creating a Parser Instance

> **⚠️ Important:** In ProcessFlow code snippets, `use` statements don't work because the code runs inside a closure. Always use fully qualified class names with the leading backslash.

```php
// Use fully qualified class name (no 'use' statement needed)
$parser = new \ZBateson\MailMimeParser\MailMimeParser();
```

### Parsing an Email

```php
// Parse raw email content (string)
$message = $parser->parse($rawEmailContent, false);

// The second parameter (false) indicates we're passing a string, not a resource
```

## Input Requirements

### Expected Input Structure

The parser expects **raw email content** as a string. This is the complete email including headers and body, exactly as received from the mail server.

When using the IMAP connector, the email data structure typically looks like:

```php
$process_input['result']['email_data'] = [
    'emails' => [
        [
            'message_number' => 1,
            'uid' => '12345',
            'subject' => 'Email Subject',
            'from' => 'sender@example.com',
            'to' => 'recipient@example.com',
            'date' => '2024-01-15 10:30:00',
            'body' => '... raw MIME content ...'  // This is what the parser needs
        ],
        // ... more emails
    ]
];
```

### Raw Email Format

The `body` field should contain the complete raw email, for example:

```
MIME-Version: 1.0
Content-Type: multipart/mixed; boundary="----=_Part_123"
Subject: Test Email
From: sender@example.com
To: recipient@example.com
Date: Mon, 15 Jan 2024 10:30:00 +0000

------=_Part_123
Content-Type: text/plain; charset="UTF-8"
Content-Transfer-Encoding: quoted-printable

This is the plain text body.

------=_Part_123
Content-Type: application/pdf; name="document.pdf"
Content-Disposition: attachment; filename="document.pdf"
Content-Transfer-Encoding: base64

JVBERi0xLjQKJeLjz9MKMSAwIG9iago8PAovVHlwZSAvQ2F0YWxvZwo+Pg...

------=_Part_123--
```

## Output Structure

### Extracting Content

```php
// Get plain text body (null if not present)
$textPlain = $message->getTextContent();

// Get HTML body (null if not present)
$textHtml = $message->getHtmlContent();

// Get specific header values
$subject = $message->getHeaderValue('subject');
$from = $message->getHeaderValue('from');
$to = $message->getHeaderValue('to');
$date = $message->getHeaderValue('date');
```

### Extracting Attachments

```php
// Get all attachment parts
foreach ($message->getAllAttachmentParts() as $attachment) {
    // Get filename (automatically decoded from RFC 2047 if needed)
    $filename = $attachment->getFilename();
    
    // Get MIME content type (e.g., "application/pdf")
    $contentType = $attachment->getContentType();
    
    // Get decoded binary content (base64/quoted-printable automatically decoded)
    $content = $attachment->getContent();
    
    // Get content as stream resource (for large files)
    $stream = $attachment->getContentStream();
}
```

### Attachment Object Properties

| Method | Return Type | Description |
|--------|-------------|-------------|
| `getFilename()` | `string\|null` | Decoded filename from Content-Disposition or Content-Type |
| `getContentType()` | `string` | MIME type (e.g., "application/pdf", "image/jpeg") |
| `getContent()` | `string` | Decoded binary content |
| `getContentStream()` | `resource` | Stream resource for large files |
| `getContentId()` | `string\|null` | Content-ID for inline attachments |
| `getCharset()` | `string\|null` | Character set for text attachments |

## Complete Example

The following code snippet demonstrates parsing emails from an IMAP integration and extracting attachments:

```php
<?php
/**
 * Parse attachments from IMAP emails using zbateson/mail-mime-parser
 * 
 * Requires: composer require zbateson/mail-mime-parser
 * 
 * Note: Use fully qualified class names (no 'use' statements) in ProcessFlow code snippets
 */

$emailData = $process_input['result']['email_data'] ?? null;

if (!$emailData) {
    return ['success' => false, 'error' => ['code' => 'EMAIL_DATA_NOT_FOUND', 'message' => 'Email data not found'], 'data' => null];
}

$emails = $emailData['emails'] ?? [];
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
```

## Output Data Structure

The example code snippet returns the following structure:

```json
{
    "success": true,
    "data": {
        "emails_processed": 2,
        "emails": [
            {
                "message_number": 1,
                "uid": "12345",
                "subject": "Invoice for January",
                "from": "billing@company.com",
                "to": "finance@mycompany.com",
                "date": "2024-01-15 10:30:00",
                "body_plain": "Please find attached the invoice...",
                "body_html": "<html><body><p>Please find attached...</p></body></html>",
                "has_attachments": true,
                "attachment_count": 1,
                "attachments": [
                    {
                        "original_filename": "invoice_jan_2024.pdf",
                        "saved_filename": "invoice_jan_2024.pdf",
                        "relative_path": "emails/1/attachments/invoice_jan_2024.pdf",
                        "size": 125432,
                        "content_type": "application/pdf",
                        "saved": true
                    }
                ],
                "parsed": true
            },
            {
                "message_number": 2,
                "subject": "Error email",
                "from": "unknown@test.com",
                "parsed": false,
                "reason": "No body content"
            }
        ]
    },
    "message": "Processed 2 email(s) with attachment extraction"
}
```

## Tenant-Scoped File Operations

In ProcessFlow code snippets, standard PHP file functions are **not available directly**. Instead, tenant-scoped wrapper functions are provided as **closure variables** (with `$` prefix). These automatically scope all file operations to `storage/tenantdata/<tenant_id>/`.

### Available File Functions

| Native PHP | ProcessFlow Equivalent | Description |
|------------|------------------------|-------------|
| `file_put_contents()` | `$file_put_contents()` | Write data to file |
| `file_get_contents()` | `$file_get_contents()` | Read file contents |
| `file_exists()` | `$file_exists()` | Check if file exists |
| `is_dir()` | `$is_dir()` | Check if path is directory |
| `is_file()` | `$is_file()` | Check if path is file |
| `mkdir()` | `$mkdir()` | Create directory |
| `rmdir()` | `$rmdir()` | Remove directory |
| `chmod()` | `$chmod()` | Change file permissions |
| `unlink()` | `$unlink()` | Delete file |
| `copy()` | `$copy()` | Copy file |
| `rename()` | `$rename()` | Rename/move file |
| `scandir()` | `$scandir()` | List directory contents |
| `glob()` | `$glob()` | Find files by pattern |
| `fopen()` | `$fopen()` | Open file handle |
| `fclose()` | `$fclose()` | Close file handle |
| `fread()` | `$fread()` | Read from file handle |
| `fwrite()` | `$fwrite()` | Write to file handle |
| `fgets()` | `$fgets()` | Read line from file |
| `filesize()` | `$filesize()` | Get file size |
| `filemtime()` | `$filemtime()` | Get modification time |

### Native Functions (Not Sandboxed)

Some functions work directly without the `$` prefix:
- `pathinfo()` - Parse path information
- `basename()` - Get filename from path
- `dirname()` - Get directory from path
- `strlen()` - String length
- `preg_replace()` - Regular expression replace

### Example Usage

```php
// CORRECT - Use closure variables for file operations
$mkdir('my-folder', 0755, true);
$file_put_contents('my-folder/data.json', json_encode($data));
$content = $file_get_contents('my-folder/data.json');

// WRONG - Native functions are blocked
mkdir('my-folder', 0755, true);  // Will fail
file_put_contents('my-folder/data.json', $data);  // Will fail
```

### File Path Scoping

All paths are automatically prefixed with the tenant's data directory:
- Input path: `emails/1/attachments/file.pdf`
- Actual path: `storage/tenantdata/<tenant_id>/emails/1/attachments/file.pdf`

This ensures tenant isolation - code snippets cannot access files outside their tenant's directory.

## Advanced Usage

### Working with Inline Images

Inline images (embedded in HTML) have a Content-ID and are typically referenced in HTML as `cid:content-id`:

```php
// Get only inline parts (not attachments)
foreach ($message->getAllParts() as $part) {
    $contentId = $part->getContentId();
    $disposition = $part->getContentDisposition();
    
    if ($contentId && $disposition === 'inline') {
        // This is an inline image
        $content = $part->getContent();
        // Replace cid: references in HTML with base64 data URLs or saved paths
    }
}
```

### Handling Large Attachments

For memory efficiency with large attachments, use streams:

```php
foreach ($message->getAllAttachmentParts() as $attachment) {
    $filename = $attachment->getFilename();
    $stream = $attachment->getContentStream();
    
    // Write directly to file without loading into memory
    $fp = fopen('attachments/' . $filename, 'wb');
    stream_copy_to_stream($stream, $fp);
    fclose($fp);
}
```

### Getting All Headers

```php
// Get all headers as an array
$headers = [];
foreach ($message->getAllHeaders() as $header) {
    $headers[$header->getName()] = $header->getValue();
}

// Common headers
$messageId = $message->getHeaderValue('message-id');
$inReplyTo = $message->getHeaderValue('in-reply-to');
$references = $message->getHeaderValue('references');
$contentType = $message->getHeaderValue('content-type');
```

## Error Handling

Always wrap parsing in try-catch blocks as malformed emails can throw exceptions:

```php
try {
    $message = $parser->parse($rawContent, false);
    // Process message...
} catch (\ZBateson\MailMimeParser\Parser\ParserException $e) {
    // Handle parse errors
    error_log("MIME parse error: " . $e->getMessage());
} catch (Exception $e) {
    // Handle other errors
    error_log("Email processing error: " . $e->getMessage());
}
```

## See Also

- [ProcessFlow Code Snippets Guide](../PROCESSFLOW_CODE_SNIPPETS_GUIDE.md)
- [zbateson/mail-mime-parser Documentation](https://mail-mime-parser.org/)

Integration workers are documented in the platform repository.
