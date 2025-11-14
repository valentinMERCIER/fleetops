<?php

namespace Fleetbase\FleetOps\Support;

use Illuminate\Contracts\Validation\Validator;
use Carbon\Carbon;

/**
 * Validation result container with errors, warnings, and suggestions
 */
class ValidationResult
{
    protected array $errors = [];
    protected array $warnings = [];
    protected array $suggestions = [];
    protected bool $valid = true;
    protected $validator = null;
    protected array $metadata = [];

    /**
     * Create validation result from Laravel validator
     * 
     * @param Validator|object|null $validator Laravel validator instance or mock
     */
    public function __construct($validator = null)
    {
        $this->validator = $validator;
        
        if ($validator) {
            $this->valid = !$validator->fails();
            
            if ($validator->fails()) {
                $this->errors = $validator->errors()->toArray();
                $this->generateSuggestions();
            }
            
            // Check for warnings based on data
            $this->checkWarnings($validator->getData());
        }
    }

    /**
     * Static constructor for manual validation
     * 
     * @return self
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Add an error
     * 
     * @param string $field Field name
     * @param string $message Error message
     * @return self
     */
    public function addError(string $field, string $message): self
    {
        $this->errors[$field][] = $message;
        $this->valid = false;
        return $this;
    }

    /**
     * Add a warning
     * 
     * @param string $field Field name
     * @param string $message Warning message
     * @return self
     */
    public function addWarning(string $field, string $message): self
    {
        $this->warnings[$field][] = $message;
        return $this;
    }

    /**
     * Add a suggestion
     * 
     * @param string $field Field name
     * @param string $suggestion Fix suggestion
     * @return self
     */
    public function addSuggestion(string $field, string $suggestion): self
    {
        $this->suggestions[$field][] = $suggestion;
        return $this;
    }

    /**
     * Add metadata
     * 
     * @param string $key Metadata key
     * @param mixed $value Metadata value
     * @return self
     */
    public function addMetadata(string $key, $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    /**
     * Check for business logic warnings
     * 
     * @param array $data Validated data
     */
    protected function checkWarnings(array $data): void
    {
        // Warning: Order scheduled very soon
        if (!empty($data['scheduled_at'])) {
            try {
                $scheduledTime = Carbon::parse($data['scheduled_at']);
                $hoursUntilScheduled = $scheduledTime->diffInHours(now());
                
                if ($hoursUntilScheduled < 2 && $hoursUntilScheduled >= 0) {
                    $this->addWarning('scheduled_at', 'Order is scheduled less than 2 hours from now');
                } elseif ($scheduledTime->isPast()) {
                    $this->addWarning('scheduled_at', 'Scheduled time is in the past');
                }
            } catch (\Exception $e) {
                // Invalid date handled by validation
            }
        }

        // Warning: No contact method
        $hasPhone = !empty($data['customer_phone']);
        $hasEmail = !empty($data['customer_email']);
        
        if (!$hasPhone && !$hasEmail) {
            $this->addWarning('contact', 'No contact information (phone or email) provided for customer');
        } elseif (!$hasPhone) {
            $this->addWarning('customer_phone', 'No phone number provided - communication may be limited');
        } elseif (!$hasEmail) {
            $this->addWarning('customer_email', 'No email provided - digital notifications will not be sent');
        }

        // Warning: Missing optional but recommended fields
        if (empty($data['reference'])) {
            $this->addWarning('reference', 'No reference number provided - tracking may be difficult');
        }

        // Warning: Potentially incomplete address
        if (!empty($data['pickup_address']) && strlen($data['pickup_address']) < 20) {
            $this->addWarning('pickup_address', 'Pickup address seems short - verify it includes street, city, and postal code');
        }
        
        if (!empty($data['dropoff_address']) && strlen($data['dropoff_address']) < 20) {
            $this->addWarning('dropoff_address', 'Dropoff address seems short - verify it includes street, city, and postal code');
        }
    }

    /**
     * Generate fix suggestions for validation errors
     */
    protected function generateSuggestions(): void
    {
        foreach ($this->errors as $field => $messages) {
            foreach ($messages as $message) {
                $suggestion = $this->getSuggestionForError($field, $message);
                if ($suggestion) {
                    $this->addSuggestion($field, $suggestion);
                }
            }
        }
    }

    /**
     * Get suggestion based on error message
     * 
     * @param string $field Field name
     * @param string $message Error message
     * @return string|null Suggestion or null
     */
    protected function getSuggestionForError(string $field, string $message): ?string
    {
        $suggestions = [
            'required' => 'This field is required. Please provide a value.',
            'email' => 'Please provide a valid email address (e.g., user@example.com)',
            'phone' => 'Please provide a valid phone number (10-15 digits, optional + prefix)',
            'date' => 'Please provide a valid date in format: YYYY-MM-DD HH:MM:SS',
            'after' => 'Date must be in the future',
            'min' => 'Value is too short. Please provide more details.',
            'max' => 'Value is too long. Please shorten the text.',
            'numeric' => 'Please provide a numeric value',
            'regex' => 'Format is invalid. Please check the format requirements.',
        ];

        foreach ($suggestions as $keyword => $suggestion) {
            if (str_contains(strtolower($message), $keyword)) {
                return $suggestion;
            }
        }

        return null;
    }

    // Getters
    public function isValid(): bool
    {
        return $this->valid && empty($this->errors);
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
    }

    public function hasSuggestions(): bool
    {
        return !empty($this->suggestions);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function getSuggestions(): array
    {
        return $this->suggestions;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getAllIssues(): array
    {
        return [
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'suggestions' => $this->suggestions,
            'is_valid' => $this->isValid(),
            'metadata' => $this->metadata
        ];
    }

    /**
     * Get flat list of all error messages
     * 
     * @return array
     */
    public function getErrorMessages(): array
    {
        $messages = [];
        foreach ($this->errors as $field => $fieldErrors) {
            foreach ($fieldErrors as $error) {
                $messages[] = "$field: $error";
            }
        }
        return $messages;
    }

    /**
     * Get severity level
     * 
     * @return string
     */
    public function getSeverity(): string
    {
        if ($this->hasErrors()) {
            return 'error';
        }
        if ($this->hasWarnings()) {
            return 'warning';
        }
        return 'success';
    }
}