<?php
/**
 * Get Tenant Marketing Contacts
 * 
 * System-level process step for retrieving all users who have given marketing consent
 * 
 * This code snippet:
 * 1. Fetches all users from the Users table
 * 2. Joins with RegistrationConsents to filter users with marketing consent
 * 3. Returns user fullname and email
 * 
 * Input: None required (runs in system tenant /root)
 * Output: Array of users with marketing consent (fullname, email)
 */

try {
    // Query to fetch users with marketing consent
    // Join Users with RegistrationConsents where consent_type = 'marketing_emails' and granted = 1
    $stmt = $db->prepare("
        SELECT DISTINCT
            u.user_id,
            u.full_name,
            u.email
        FROM Users u
        INNER JOIN RegistrationConsents rc ON u.user_id = rc.user_id
        WHERE rc.consent_type = 'marketing_emails'
        AND rc.granted = 1
        ORDER BY u.full_name ASC, u.email ASC
    ");
    
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format the results
    $contacts = [];
    foreach ($users as $user) {
        $contacts[] = [
            'user_id' => $user['user_id'],
            'fullname' => $user['full_name'] ?? '',
            'email' => $user['email'] ?? ''
        ];
    }
    
    return [
        'success' => true,
        'data' => [
            'contacts' => $contacts,
            'count' => count($contacts)
        ],
        'message' => 'Marketing contacts retrieved successfully'
    ];
    
} catch (PDOException $e) {
    return [
        'success' => false,
        'error' => [
            'code' => 'DATABASE_ERROR',
            'message' => 'Database operation failed',
            'details' => $e->getMessage()
        ],
        'data' => null
    ];
} catch (Exception $e) {
    return [
        'success' => false,
        'error' => [
            'code' => 'UNKNOWN_ERROR',
            'message' => 'Unexpected error occurred',
            'details' => $e->getMessage()
        ],
        'data' => null
    ];
}
