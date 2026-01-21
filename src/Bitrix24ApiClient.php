<?php

namespace BitrixInvoiceBridge;

/**
 * Bitrix24 REST API Client for lead management.
 * 
 * Uses webhook-based authentication (no OAuth2 required).
 */
class Bitrix24ApiClient
{
    private string $webhookUrl;

    public function __construct(string $webhookUrl)
    {
        $this->webhookUrl = rtrim($webhookUrl, '/');
    }

    /**
     * Make a request to Bitrix24 REST API.
     * 
     * @param string $method API method (e.g., 'crm.lead.add')
     * @param array $params Method parameters
     * @return array API response
     * @throws \Exception If request fails
     */
    public function request(string $method, array $params = []): array
    {
        $url = $this->webhookUrl . '/' . $method;
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception("Bitrix24 API request failed: {$error}");
        }

        $responseData = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid JSON response from Bitrix24: {$response}");
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $errorMsg = isset($responseData['error_description']) 
                ? $responseData['error_description'] 
                : (isset($responseData['error']) ? $responseData['error'] : json_encode($responseData));
            throw new \Exception("Bitrix24 API request failed with HTTP {$httpCode}: {$errorMsg}");
        }

        // Check for Bitrix24-specific error in response
        if (isset($responseData['error']) && $responseData['error'] !== '') {
            $errorMsg = $responseData['error_description'] ?? $responseData['error'];
            throw new \Exception("Bitrix24 API error: {$errorMsg}");
        }

        return $responseData;
    }

    /**
     * Create a new lead in Bitrix24.
     * 
     * @param array $leadData Lead data (fields mapping from InVoice)
     * @return array API response with lead ID
     * @throws \Exception If creation fails
     */
    public function createLead(array $leadData): array
    {
        return $this->request('crm.lead.add', [
            'fields' => $leadData
        ]);
    }

    /**
     * Update an existing lead in Bitrix24.
     * 
     * @param int $leadId Bitrix24 lead ID
     * @param array $leadData Lead data to update
     * @return array API response
     * @throws \Exception If update fails
     */
    public function updateLead(int $leadId, array $leadData): array
    {
        return $this->request('crm.lead.update', [
            'id' => $leadId,
            'fields' => $leadData
        ]);
    }

    /**
     * Find a lead by phone number.
     * 
     * @param string $phone Phone number to search for
     * @return array|null Lead data if found, null otherwise
     * @throws \Exception If search fails
     */
    public function findLeadByPhone(string $phone): ?array
    {
        // Normalize phone number (remove spaces, dashes, etc.)
        $normalizedPhone = preg_replace('/[^0-9+]/', '', $phone);
        
        try {
            $result = $this->request('crm.lead.list', [
                'filter' => [
                    'PHONE' => $normalizedPhone
                ],
                'select' => ['ID', 'TITLE', 'NAME', 'PHONE', 'UF_CRM_INVOICE_ID_ANAGRAFICA']
            ]);
            
            if (isset($result['result']) && !empty($result['result'])) {
                return $result['result'][0]; // Return first match
            }
            
            return null;
        } catch (\Exception $e) {
            // If search fails, return null (lead not found)
            if (strpos($e->getMessage(), 'not found') !== false) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Create a new contact in Bitrix24.
     * 
     * @param array $contactData Contact data (fields mapping from InVoice)
     * @return array API response with contact ID
     * @throws \Exception If creation fails
     */
    public function createContact(array $contactData): array
    {
        return $this->request('crm.contact.add', [
            'fields' => $contactData
        ]);
    }

    /**
     * Update an existing contact in Bitrix24.
     * 
     * @param int $contactId Bitrix24 contact ID
     * @param array $contactData Contact data to update
     * @return array API response
     * @throws \Exception If update fails
     */
    public function updateContact(int $contactId, array $contactData): array
    {
        return $this->request('crm.contact.update', [
            'id' => $contactId,
            'fields' => $contactData
        ]);
    }

    /**
     * Find a contact by phone number.
     * 
     * @param string $phone Phone number to search for
     * @return array|null Contact data if found, null otherwise
     * @throws \Exception If search fails
     */
    public function findContactByPhone(string $phone): ?array
    {
        // Normalize phone number (remove spaces, dashes, etc.)
        $normalizedPhone = preg_replace('/[^0-9+]/', '', $phone);
        
        try {
            $result = $this->request('crm.contact.list', [
                'filter' => [
                    'PHONE' => $normalizedPhone
                ],
                'select' => ['ID', 'NAME', 'LAST_NAME', 'PHONE', 'UF_CRM_INVOICE_ID_ANAGRAFICA']
            ]);
            
            if (isset($result['result']) && !empty($result['result'])) {
                return $result['result'][0]; // Return first match
            }
            
            return null;
        } catch (\Exception $e) {
            // If search fails, return null (contact not found)
            if (strpos($e->getMessage(), 'not found') !== false) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Create a new deal in Bitrix24.
     * 
     * @param array $dealData Deal data
     * @return array API response with deal ID
     * @throws \Exception If creation fails
     */
    public function createDeal(array $dealData): array
    {
        return $this->request('crm.deal.add', [
            'fields' => $dealData
        ]);
    }

    /**
     * Link a deal to a contact.
     * 
     * @param int $dealId Bitrix24 deal ID
     * @param int $contactId Bitrix24 contact ID
     * @return array API response
     * @throws \Exception If linking fails
     */
    public function linkDealToContact(int $dealId, int $contactId): array
    {
        // Update deal with contact ID
        return $this->request('crm.deal.update', [
            'id' => $dealId,
            'fields' => [
                'CONTACT_ID' => $contactId
            ]
        ]);
    }

    /**
     * Get deal by ID.
     */
    public function getDeal(int $dealId): array
    {
        return $this->request('crm.deal.get', ['id' => $dealId]);
    }

    /**
     * Get contact by ID.
     */
    public function getContact(int $contactId): array
    {
        return $this->request('crm.contact.get', ['id' => $contactId]);
    }

    /**
     * Get CRM activity by ID.
     * NOTE: The exact payload depends on your Bitrix configuration and event type.
     */
    public function getActivity(int $activityId): array
    {
        return $this->request('crm.activity.get', ['id' => $activityId]);
    }

    /**
     * Get campaign name from id_config_campagna.
     * 
     * @param int $idConfigCampagna Campaign configuration ID from InVoice
     * @return string Campaign name (from config) or ID as string if not found
     */
    public static function getCampaignName(int $idConfigCampagna): string
    {
        static $campaignMap = null;
        
        // Load campaign mapping once
        if ($campaignMap === null) {
            $configFile = __DIR__ . '/../config/campaigns.php';
            if (file_exists($configFile)) {
                $campaignMap = require $configFile;
                if (!is_array($campaignMap)) {
                    $campaignMap = [];
                }
            } else {
                $campaignMap = [];
            }
        }
        
        // Return mapped name or ID as fallback
        return $campaignMap[$idConfigCampagna] ?? (string)$idConfigCampagna;
    }

    /**
     * Map InVoice lot data to Bitrix24 lead/contact fields.
     * 
     * @param array $lotData InVoice lot data (from API response)
     * @param int $lotId InVoice lot ID
     * @param int|null $idConfigCampagna Campaign configuration ID (from event payload)
     * @param string $entityType Entity type: 'lead' or 'contact'
     * @return array Bitrix24 fields
     */
    public static function mapInvoiceDataToBitrixFields(
        array $lotData,
        int $lotId,
        ?int $idConfigCampagna = null,
        ?int $idCampagna = null,
        string $entityType = 'lead'
    ): array
    {
        // Extract first lead from lot (usually lot contains one lead)
        $leadData = $lotData[0] ?? [];
        
        // Base fields for both lead and contact
        if ($entityType === 'contact') {
            $bitrixFields = [
                'NAME' => 'Contact from InVoice - Lot ' . $lotId,
                'OPENED' => 'Y', // Contact is open
            ];
        } else {
            $bitrixFields = [
                'TITLE' => 'Lead from InVoice - Lot ' . $lotId,
                'SOURCE_ID' => 'WEB', // Source: Web
                'OPENED' => 'Y', // Lead is open
            ];
        }
        
        // Map phone number
        if (isset($leadData['TELEFONO']) && !empty($leadData['TELEFONO'])) {
            $bitrixFields['PHONE'] = [
                [
                    'VALUE' => $leadData['TELEFONO'],
                    'VALUE_TYPE' => 'WORK'
                ]
            ];
        }
        
        // Map campaign name to SOURCE_DESCRIPTION
        if ($idConfigCampagna !== null) {
            $campaignName = self::getCampaignName($idConfigCampagna);
            $bitrixFields['SOURCE_DESCRIPTION'] = $campaignName;
        }
        
        // Map expiration date (DATA_SCADENZA) to custom field
        // Note: You need to create a custom field in Bitrix24 for this (e.g., UF_CRM_DATA_SCADENZA)
        if (isset($leadData['DATA_SCADENZA']) && !empty($leadData['DATA_SCADENZA'])) {
            // Store as string in custom field (format: DD/MM/YYYY)
            // If you have a date field, convert from DD/MM/YYYY to YYYY-MM-DD
            $dateParts = explode('/', $leadData['DATA_SCADENZA']);
            if (count($dateParts) === 3) {
                // Use custom field for expiration date (create this field in Bitrix24 first)
                $bitrixFields['UF_CRM_DATA_SCADENZA'] = $dateParts[2] . '-' . $dateParts[1] . '-' . $dateParts[0];
            } else {
                // Fallback: store as string
                $bitrixFields['UF_CRM_DATA_SCADENZA'] = $leadData['DATA_SCADENZA'];
            }
        }
        
        // Store InVoice ID_ANAGRAFICA in custom field (if available)
        if (isset($leadData['ID_ANAGRAFICA']) && !empty($leadData['ID_ANAGRAFICA'])) {
            $bitrixFields['UF_CRM_INVOICE_ID_ANAGRAFICA'] = (string)$leadData['ID_ANAGRAFICA'];
        }

        // Store InVoice lot and campaign IDs (needed for Bitrix -> InVoice worked upload).
        // NOTE: These are custom fields and must be created in Bitrix24 before use.
        $bitrixFields['UF_CRM_INVOICE_LOT_ID'] = (string)$lotId;
        if ($idCampagna !== null) {
            $bitrixFields['UF_CRM_INVOICE_CAMPAIGN_ID'] = (string)$idCampagna;
        }
        if ($idConfigCampagna !== null) {
            $bitrixFields['UF_CRM_INVOICE_CAMPAIGN_CONFIG_ID'] = (string)$idConfigCampagna;
        }
        
        // Store lot ID in comments or custom field
        $comments = "InVoice Lot ID: {$lotId}\n" . 
            (isset($leadData['ID_ANAGRAFICA']) ? "ID Anagrafica: {$leadData['ID_ANAGRAFICA']}\n" : '');
        
        if ($entityType === 'contact') {
            $bitrixFields['COMMENTS'] = $comments;
        } else {
            $bitrixFields['COMMENTS'] = $comments;
        }
        
        return $bitrixFields;
    }

    /**
     * Map InVoice lot data to Bitrix24 deal fields.
     * 
     * @param array $lotData InVoice lot data (from API response)
     * @param int $lotId InVoice lot ID
     * @param int|null $idConfigCampagna Campaign configuration ID (from event payload)
     * @param int|null $pipelineId Pipeline ID for the deal
     * @return array Bitrix24 deal fields
     */
    public static function mapInvoiceDataToDealFields(
        array $lotData,
        int $lotId,
        ?int $idConfigCampagna = null,
        ?int $idCampagna = null,
        ?int $pipelineId = null
    ): array
    {
        // Extract first lead from lot
        $leadData = $lotData[0] ?? [];
        
        $dealFields = [
            'TITLE' => 'Deal from InVoice - Lot ' . $lotId,
            'OPENED' => 'Y', // Deal is open
        ];
        
        // Set pipeline if provided
        if ($pipelineId !== null && $pipelineId > 0) {
            $dealFields['CATEGORY_ID'] = $pipelineId;
        }
        
        // Map campaign name to SOURCE_DESCRIPTION
        if ($idConfigCampagna !== null) {
            $campaignName = self::getCampaignName($idConfigCampagna);
            $dealFields['SOURCE_DESCRIPTION'] = $campaignName;
        }
        
        // Store lot ID in comments
        $dealFields['COMMENTS'] = "InVoice Lot ID: {$lotId}\n" . 
            (isset($leadData['ID_ANAGRAFICA']) ? "ID Anagrafica: {$leadData['ID_ANAGRAFICA']}\n" : '');

        // Store InVoice references on the deal as custom fields as well (recommended).
        $dealFields['UF_CRM_INVOICE_LOT_ID'] = (string)$lotId;
        if ($idCampagna !== null) {
            $dealFields['UF_CRM_INVOICE_CAMPAIGN_ID'] = (string)$idCampagna;
        }
        if ($idConfigCampagna !== null) {
            $dealFields['UF_CRM_INVOICE_CAMPAIGN_CONFIG_ID'] = (string)$idConfigCampagna;
        }
        if (isset($leadData['ID_ANAGRAFICA']) && !empty($leadData['ID_ANAGRAFICA'])) {
            $dealFields['UF_CRM_INVOICE_ID_ANAGRAFICA'] = (string)$leadData['ID_ANAGRAFICA'];
        }
        
        return $dealFields;
    }
}
