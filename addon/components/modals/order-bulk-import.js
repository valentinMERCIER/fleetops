import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action, set } from '@ember/object';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';
import config from 'ember-get-config';
import { format } from 'date-fns';

export default class ModalsOrderBulkImportComponent extends Component {
    @service store;
    @service fetch;
    @service notifications;
    @service intl;
    @service scheduledImport;
    @service universe;

    @tracked currentStep = 1;
    @tracked file;
    @tracked mappings = [];
    @tracked dryRunResults = [];
    @tracked importResult;
    @tracked isUploading = false;
    @tracked isProcessing = false;
    @tracked isAutoImporting = false;
    @tracked _mappingsVersion = 0; // Version counter for manual reactivity

    @tracked showScheduleModal = false;
    
    constructor() {
        super(...arguments);
        console.log('🔄 OrderBulkImport: Constructor - args received:', {
            hasOnSuccess: !!this.args.onSuccess,
            argsKeys: Object.keys(this.args),
            args: this.args
        });
    }
    @tracked dateFormat = 'yyyy-MM-dd HH:mm:ss'; // Default date format
    @tracked dateFormatError = null; // Track date format validation errors

    get title() {
        if (this.isAutoImporting) {
            return 'Importing Orders';
        }
        
        switch (this.currentStep) {
            case 1:
                return 'Import Orders';
            case 2:
                return 'Create Order Template';
            case 3:
                return 'Review Issues';
            case 4:
                return 'Import Complete';
            case 5:
                return 'Schedule Import';
            case 6:
                return 'Scheduled Imports';
            default:
                return 'Import Orders';
        }
    }

    get subTitle() {
        if (this.isAutoImporting) {
            return 'All validation passed! Creating orders automatically...';
        }
        
        switch (this.currentStep) {
            case 1:
                return 'Choose a file to import orders';
            case 2:
                return 'Match the columns in your file to the corresponding order attributes';
            case 3:
                return 'Click Continue to import without editing. To correct issues click cancel and import again.';
            case 4:
                if (this.importResult) {
                    if (this.importResult.success === 0) {
                        return 'No orders imported';
                    } else if (this.importResult.success === 1) {
                        return '1 order imported successfully';
                    } else {
                        return `${this.importResult.success} orders imported successfully`;
                    }
                }
                return '';
            case 5:
                return 'Review the schedule';
            case 6:
                return 'Manage Scheduled Imports';
            default:
                return 'Import Orders';
        }
    }



    get nextButtonText() {
        switch (this.currentStep) {
            case 1:
                return 'Next';
            case 2:
                return 'Import';
            case 3:
                return 'Continue';
            case 4:
                return 'Done';
            case 5:
                return 'Schedule';
            default:
                return 'Next';
        }
    }

    get nextButtonScheme() {
        return 'primary';
    }

    get backButtonText() {
        return 'Back';
    }

    get showNextButton() {
        return this.currentStep !== 1; // Step 1 uses the "Choose file" button as the primary action trigger effectively, or we keep it hidden until file selected
    }

    @tracked csvHeaders = [];

    get systemFields() {
        return [
            {
                group: 'Order Details',
                fields: [
                    { label: 'Import ID (External ID)', key: 'import_id' },
                    { label: 'Internal ID', key: 'internal_id' },
                    { label: 'Order Type', key: 'type', required: true },
                    { label: 'Status', key: 'status' },
                    { label: 'Scheduled At', key: 'scheduled_at' },
                    { label: 'Customer', key: 'customer' },
                    { label: 'Facilitator', key: 'facilitator' },
                    { label: 'Driver', key: 'driver' },
                    { label: 'Vehicle', key: 'vehicle' },
                    { label: 'Notes', key: 'notes' },
                ]
            },
            {
                group: 'Pickup (Name/ID or Address required)',
                fields: [
                    { label: 'Pickup Name/ID', key: 'pickup_name', conditionallyRequired: true },
                    { label: 'Pickup Address', key: 'pickup_address', conditionallyRequired: true },
                    { label: 'Pickup Phone', key: 'pickup_phone' },
                    { label: 'Pickup Contact Name', key: 'pickup_contact_name' },
                ]
            },
            {
                group: 'Dropoff (Name/ID or Address required)',
                fields: [
                    { label: 'Dropoff Name/ID', key: 'dropoff_name', conditionallyRequired: true },
                    { label: 'Dropoff Address', key: 'dropoff_address', conditionallyRequired: true },
                    { label: 'Dropoff Phone', key: 'dropoff_phone' },
                    { label: 'Dropoff Contact Name', key: 'dropoff_contact_name' },
                ]
            },
            {
                group: 'Return',
                fields: [
                    { label: 'Return Name/ID', key: 'return' },
                    { label: 'Return Address', key: 'return_address' },
                    { label: 'Return Phone', key: 'return_phone' },
                    { label: 'Return Contact Name', key: 'return_contact_name' },
                ]
            },
            {
                group: 'Settings & Options',
                fields: [
                    { label: 'Service Type', key: 'service_type' },
                    { label: 'POD Method', key: 'pod_method' },
                    { label: 'Adhoc (Yes/No)', key: 'adhoc' },
                    { label: 'Adhoc Distance', key: 'adhoc_distance' },
                    { label: 'Auto Dispatch (Yes/No)', key: 'dispatched' },
                    { label: 'POD Required (Yes/No)', key: 'pod_required' },
                ]
            },
            {
                group: 'Payload',
                fields: [
                    { label: 'Entities (Items)', key: 'entities' },
                ]
            }
        ];
    }

    // Common date format options
    get commonDateFormats() {
        return [
            { label: 'yyyy-MM-dd HH:mm:ss (2024-01-15 14:30:00)', value: 'yyyy-MM-dd HH:mm:ss' },
            { label: 'MM/dd/yyyy HH:mm (01/15/2024 14:30)', value: 'MM/dd/yyyy HH:mm' },
            { label: 'dd/MM/yyyy HH:mm (15/01/2024 14:30)', value: 'dd/MM/yyyy HH:mm' },
            { label: 'yyyy-MM-dd (2024-01-15)', value: 'yyyy-MM-dd' },
            { label: 'MM/dd/yyyy (01/15/2024)', value: 'MM/dd/yyyy' },
            { label: 'dd/MM/yyyy (15/01/2024)', value: 'dd/MM/yyyy' },
            { label: 'MM-dd-yyyy (01-15-2024)', value: 'MM-dd-yyyy' },
            { label: 'dd-MM-yyyy (15-01-2024)', value: 'dd-MM-yyyy' },
        ];
    }

    // Generate live preview of date format (safe getter without side effects)
    get dateFormatPreview() {
        if (!this.dateFormat) {
            return '';
        }

        try {
            // Use a sample date for preview
            const sampleDate = new Date(2024, 0, 15, 14, 30, 0); // January 15, 2024 14:30:00
            return format(sampleDate, this.dateFormat);
        } catch (error) {
            return 'Invalid format';
        }
    }

    // Separate getter for checking if format is valid
    get isDateFormatValid() {
        if (!this.dateFormat) {
            return false;
        }

        try {
            const sampleDate = new Date(2024, 0, 15, 14, 30, 0);
            format(sampleDate, this.dateFormat);
            return true;
        } catch (error) {
            return false;
        }
    }

    // Check if scheduled_at field is mapped to a column
    get isScheduledAtMapped() {
        this._mappingsVersion; // eslint-disable-line no-unused-expressions
        
        if (!this.mappings) return false;
        
        for (const group of this.mappings) {
            const scheduledAtField = group.fields.find(f => f.key === 'scheduled_at');
            if (scheduledAtField && scheduledAtField.selectedColumn && scheduledAtField.selectedColumn.trim() !== '') {
                console.log('📅 Date format field should be visible - scheduled_at is mapped to:', scheduledAtField.selectedColumn);
                return true;
            }
        }
        console.log('📅 Date format field should be hidden - scheduled_at is not mapped');
        return false;
    }

    @tracked importSession = null;

    @action
    async handleUpload(file) {
        this.file = file;
        this.isUploading = true;

        try {
            // Construct URL
            const host = config.API.host;
            const namespace = config.API.namespace;
            const url = [host, namespace, 'orders/import-sessions'].filter(Boolean).join('/');

            console.log('Upload URL:', url);

            // Get headers
            const headers = this.fetch.getHeaders();
            delete headers['Content-Type'];

            // Use ember-file-upload's upload method
            const response = await file.upload(url, {
                data: {
                    auto_detect_mappings: true
                },
                headers,
                withCredentials: true
            });

            // The response from file.upload is the raw response object
            // We need to parse the JSON body
            const responseBody = await response.json();
            console.log('Response body:', responseBody);

            if (!responseBody || !responseBody.data || !responseBody.data.session) {
                throw new Error('Invalid response from server');
            }

            // Store the session info
            this.importSession = responseBody.data.session;
            const sessionId = this.importSession.id;

            // Get auto-detected mappings
            const mappingsResponse = await this.fetch.post(`orders/import-sessions/${sessionId}/detect-mappings`);

            if (!mappingsResponse || !mappingsResponse.data) {
                throw new Error('Failed to detect field mappings');
            }

            const detectedMappings = mappingsResponse.data.mappings || {};
            const parsedData = responseBody.data.parsed || {};
            this.csvHeaders = parsedData.headers || [];

            // Initialize mappings with detected values
            this.mappings = this.systemFields.map(group => {
                return {
                    group: group.group,
                    fields: group.fields.map(field => {
                        // Use detected mapping if available, otherwise try to match by field key
                        const detectedColumn = detectedMappings[field.key];
                        const match = detectedColumn || this.csvHeaders.find(h =>
                            h.toLowerCase() === field.key.toLowerCase() ||
                            h.toLowerCase() === field.label.toLowerCase()
                        );

                        return {
                            ...field,
                            selectedColumn: match || ''
                        };
                    })
                };
            });

            this.isUploading = false;
            this.nextStep();
        } catch (error) {
            this.isUploading = false;
            this.notifications.serverError(error);
            console.error('Upload error:', error);
        }
    }

    @tracked selectedImport = null;
    @tracked scheduleSettings = {
        name: 'New Scheduled Import'
    };
    @tracked previousStep = null; // Track where we came from for back navigation
    @tracked itemToDelete = null; // Store the item to delete
    @tracked isEditingSchedule = false; // Track if we're editing an existing schedule


    @action
    viewScheduledImports() {
        this.previousStep = this.currentStep;
        this.currentStep = 6;
    }

    @action
    selectImport(importItem) {
        this.selectedImport = importItem;
    }

    @action
    editSelectedImport() {
        if (this.selectedImport) {
            // Pre-fill the schedule settings with the selected import's data
            this.scheduleSettings = {
                name: this.selectedImport.name,
                frequency: this.selectedImport.options?.frequency,
                period: this.selectedImport.options?.period,
                days: this.selectedImport.options?.days || [],
                time: this.selectedImport.options?.time,
                ends: this.selectedImport.options?.ends,
                endDate: this.selectedImport.options?.end_date,
                occurrences: this.selectedImport.options?.occurrences,
            };
            // Navigate to schedule settings step to edit the selected import
            this.previousStep = 6; // Remember we came from scheduled imports
            this.isEditingSchedule = true;
            this.currentStep = 5;
        }
    }

    @action
    async deleteSelectedImport() {
        if (this.selectedImport) {
            try {
                await this.scheduledImport.delete(this.selectedImport.public_id);
                this.notifications.success('Deleted import: ' + this.selectedImport.name);
                this.itemToDelete = this.selectedImport; // Trigger list refresh in child
                this.selectedImport = null;
            } catch (error) {
                this.notifications.serverError(error);
            }
        }
    }

    @action
    updateScheduleName(name) {
        this.scheduleSettings.name = name;
    }

    @action
    scheduleImport() {
        this.isEditingSchedule = false;
        this.currentStep = 5;
    }

    @action
    async saveSchedule() {
        this.isProcessing = true;
        try {
            const data = {
                name: this.scheduleSettings.name,
                frequency: this.scheduleSettings.frequency,
                period: this.scheduleSettings.period,
                days: this.scheduleSettings.days,
                time: this.scheduleSettings.time,
                ends: this.scheduleSettings.ends,
                end_date: this.scheduleSettings.endDate,
                occurrences: this.scheduleSettings.occurrences,
                start_date: this.scheduleSettings.startDate,
                // template_uuid: this.templateUuid // TODO: Add template UUID when available
            };

            if (this.isEditingSchedule && this.selectedImport) {
                await this.scheduledImport.update(this.selectedImport.public_id, data);
                this.notifications.success('Scheduled import updated successfully');
            } else {
                await this.scheduledImport.create(data);
                this.notifications.success('Import scheduled successfully');
            }

            // Navigate to scheduled imports instead of closing
            this.currentStep = 6;
        } catch (error) {
            this.notifications.serverError(error);
        } finally {
            this.isProcessing = false;
        }
    }

    @action
    reset() {
        this.currentStep = 1;
        this.file = null;
        this.mappings = [];
        this.importResult = null;
        this.isAutoImporting = false;
    }

    @action
    nextStep() {
        if (this.currentStep === 2) {
            // Validate mappings before proceeding
            if (this.hasValidationErrors) {
                this.focusFirstErrorField();
                return;
            }
            this.executeDryRun.perform();
        } else if (this.currentStep === 3) {
            this.executeImport.perform();
        } else if (this.currentStep === 5) {
            this.saveSchedule();
        } else if (this.currentStep < 4) {
            this.currentStep++;
        } else {
            this.args.onConfirm();
        }
    }

    @task
    * executeDryRun() {
        this.isProcessing = true;
        try {
            const sessionId = this.importSession.id;

            // Format mappings for API
            const mappings = {};
            this.mappings.forEach(group => {
                group.fields.forEach(field => {
                    if (field.selectedColumn) {
                        mappings[field.key] = field.selectedColumn;
                    }
                });
            });

            // Prepare data for API including date format if scheduled_at is mapped
            const requestData = { mappings };
            if (this.isScheduledAtMapped && this.dateFormat) {
                requestData.date_format = this.dateFormat;
            }

            // Call dry run endpoint
            const response = yield this.fetch.post(`orders/import-sessions/${sessionId}/dry-run`, requestData);

            if (!response || !response.data) {
                throw new Error('Invalid response from server');
            }

            const results = response.data;

            // Transform errors for display (only showing errors, not warnings)
            const errors = results.sample_errors || [];

            // Parse errors - backend returns { row_number, errors: {field: [messages]}, original_data }
            this.dryRunResults = errors.map(e => {
                // Get the first field with an error
                const errorFields = e.errors || {};
                const firstField = Object.keys(errorFields)[0];
                const firstError = errorFields[firstField];

                // Format the error message
                let message = 'Unknown error';
                if (Array.isArray(firstError)) {
                    message = firstError[0]; // Take first error message
                } else if (typeof firstError === 'string') {
                    message = firstError;
                }

                return {
                    row: e.row_number || 'Unknown',
                    column: firstField || 'General',
                    message: message,
                    type: 'error'
                };
            });

            // Check if validation passed (no errors, can proceed)
            const canProceed = results.can_proceed || false;
            const hasErrors = errors.length > 0;

            if (canProceed && !hasErrors) {
                // Auto-proceed to import if no validation errors
                console.log('No validation errors found, automatically proceeding to import...');
                // Set a flag to show auto-import loading state
                this.isAutoImporting = true;
                this.executeImport.perform();
            } else {
                // Show review issues screen if there are errors
                this.currentStep = 3;
            }

        } catch (error) {
            this.notifications.serverError(error);
        } finally {
            this.isProcessing = false;
        }
    }

    @action
    prevStep() {
        // If we're on step 5 and came from step 6 (scheduled imports), go back to step 6
        if (this.currentStep === 5 && this.previousStep === 6) {
            this.currentStep = 6;
            this.previousStep = null;
            this.selectedImport = null; // Clear selection when going back
            this.isEditingSchedule = false; // Reset editing state
        } else if (this.currentStep > 1) {
            this.currentStep--;
        }
    }

    @action
    updateMapping(map, event) {
        set(map, 'selectedColumn', event.target.value);
        // Increment version counter to trigger getter recomputation
        this._mappingsVersion++;
    }

    @action
    updateDateFormat(event) {
        this.dateFormat = event.target.value;
        // Clear error when user starts typing
        this.dateFormatError = null;
        this.validateDateFormat();
    }

    @action
    validateDateFormat() {
        if (!this.dateFormat || this.dateFormat.trim() === '') {
            this.dateFormatError = null;
            return;
        }

        try {
            const sampleDate = new Date(2024, 0, 15, 14, 30, 0);
            format(sampleDate, this.dateFormat);
            this.dateFormatError = null;
        } catch (error) {
            this.dateFormatError = 'Invalid date format';
        }
    }

    @action
    selectCommonFormat(formatValue) {
        this.dateFormat = formatValue;
        this.dateFormatError = null;
        this.validateDateFormat();
    }

    // Helper to check if both pickup fields are empty
    get isPickupGroupEmpty() {
        // Access the version counter to ensure this getter recomputes when mappings change
        this._mappingsVersion; // eslint-disable-line no-unused-expressions
        
        if (!this.mappings) return false;
        const pickupGroup = this.mappings.find(g => g.group.includes('Pickup'));
        if (!pickupGroup) return false;

        const pickupName = pickupGroup.fields.find(f => f.key === 'pickup_name');
        const pickupAddress = pickupGroup.fields.find(f => f.key === 'pickup_address');

        const isEmpty = (!pickupName?.selectedColumn && !pickupAddress?.selectedColumn);
        console.log('isPickupGroupEmpty:', isEmpty, {
            pickupNameSelected: pickupName?.selectedColumn,
            pickupAddressSelected: pickupAddress?.selectedColumn
        });
        return isEmpty;
    }

    // Helper to check if both dropoff fields are empty
    get isDropoffGroupEmpty() {
        // Access the version counter to ensure this getter recomputes when mappings change
        this._mappingsVersion; // eslint-disable-line no-unused-expressions
        
        if (!this.mappings) return false;
        const dropoffGroup = this.mappings.find(g => g.group.includes('Dropoff'));
        if (!dropoffGroup) return false;

        const dropoffName = dropoffGroup.fields.find(f => f.key === 'dropoff_name');
        const dropoffAddress = dropoffGroup.fields.find(f => f.key === 'dropoff_address');

        const isEmpty = (!dropoffName?.selectedColumn && !dropoffAddress?.selectedColumn);
        console.log('isDropoffGroupEmpty:', isEmpty, {
            dropoffNameSelected: dropoffName?.selectedColumn,
            dropoffAddressSelected: dropoffAddress?.selectedColumn
        });
        return isEmpty;
    }

    // Check if there are any validation errors
    get hasValidationErrors() {
        this._mappingsVersion; // eslint-disable-line no-unused-expressions
        
        if (!this.mappings) return false;
        
        // Check for required fields that are empty
        for (const group of this.mappings) {
            for (const field of group.fields) {
                if (field.required && !field.selectedColumn) {
                    return true;
                }
            }
        }
        
        // Check for conditionally required fields (pickup and dropoff groups)
        if (this.isPickupGroupEmpty || this.isDropoffGroupEmpty) {
            return true;
        }
        
        return false;
    }

    // Focus the first field with a validation error
    @action
    focusFirstErrorField() {
        if (!this.mappings) return;
        
        // Find the first field with an error
        for (const group of this.mappings) {
            for (const field of group.fields) {
                const hasRequiredError = field.required && !field.selectedColumn;
                const hasConditionalError = field.conditionallyRequired && !field.selectedColumn &&
                    (((field.key === 'pickup_name' || field.key === 'pickup_address') && this.isPickupGroupEmpty) ||
                    ((field.key === 'dropoff_name' || field.key === 'dropoff_address') && this.isDropoffGroupEmpty));
                
                if (hasRequiredError || hasConditionalError) {
                    // Focus the select element for this field
                    const selector = `select[data-field-key="${field.key}"]`;
                    const element = document.querySelector(selector);
                    if (element) {
                        // Focus the element first
                        element.focus();
                        
                        // Find the scrollable container (modal body with overflow-y-auto)
                        const scrollContainer = element.closest('.overflow-y-auto');
                        if (scrollContainer) {
                            // Calculate element position relative to the scroll container
                            const containerRect = scrollContainer.getBoundingClientRect();
                            const elementRect = element.getBoundingClientRect();
                            const relativeTop = elementRect.top - containerRect.top + scrollContainer.scrollTop;
                            const containerHeight = scrollContainer.clientHeight;
                            const elementHeight = elementRect.height;
                            
                            // Calculate scroll position to center the element
                            const scrollTop = relativeTop - (containerHeight / 2) + (elementHeight / 2);
                            
                            // Smooth scroll to the calculated position
                            scrollContainer.scrollTo({
                                top: Math.max(0, scrollTop),
                                behavior: 'smooth'
                            });
                        } else {
                            // Fallback to standard scrollIntoView if container not found
                            element.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                        
                        console.log('Focused field:', field.key, field.label);
                        return;
                    }
                }
            }
        }
    }

    @task
    * executeImport() {
        this.isProcessing = true;
        try {
            const sessionId = this.importSession.id;

            // Prepare data for execution including date format if needed
            const requestData = {};
            if (this.isScheduledAtMapped && this.dateFormat) {
                requestData.date_format = this.dateFormat;
            }

            // Call execute endpoint
            const response = yield this.fetch.post(`orders/import-sessions/${sessionId}/execute`, requestData);

            if (!response || !response.data) {
                throw new Error('Invalid response from server');
            }

            const results = response.data;

            this.importResult = {
                success: results.created,
                failed: results.failed
            };

            // Trigger table refresh callback if import was successful
            if (results.created > 0 && this.args.onSuccess) {
                console.log('📥 OrderBulkImport: Import successful, calling onSuccess callback', { 
                    created: results.created, 
                    hasCallback: !!this.args.onSuccess 
                });
                try {
                    // Add a small delay to ensure the import is fully processed on the server
                    yield new Promise(resolve => setTimeout(resolve, 500));
                    console.log('🔄 OrderBulkImport: Starting refresh callback...');
                    
                    // Call the refresh callback which will handle route and table refresh
                    yield this.args.onSuccess();
                    
                    console.log('✅ OrderBulkImport: onSuccess callback completed successfully');
                    
                    // Add another small delay to ensure refresh completes before notification
                    yield new Promise(resolve => setTimeout(resolve, 250));
                    
                    // Show success notification after refresh completes
                    this.notifications.success(`Successfully imported ${results.created} orders. Table refreshed.`);
                } catch (error) {
                    // If refresh fails, still show success but note the refresh issue
                    console.error('❌ OrderBulkImport: Table refresh failed:', error);
                    console.warn('Import successful but table refresh failed:', error);
                    this.notifications.success(`Successfully imported ${results.created} orders (refresh failed).`);
                }
            } else if (results.created > 0) {
                console.log('📥 OrderBulkImport: Import successful but no callback available', { 
                    created: results.created, 
                    hasCallback: !!this.args.onSuccess 
                });
                // Fallback: Use WebSocket events to trigger refresh
                console.log('📡 OrderBulkImport: Using WebSocket fallback to trigger table refresh');
                setTimeout(() => {
                    // Trigger refresh via universe event system as fallback
                    this.universe.trigger('orders.refresh');
                    this.notifications.success(`Successfully imported ${results.created} orders. Table will refresh automatically.`);
                }, 1000);
            }

            this.currentStep = 4;
        } catch (error) {
            this.notifications.serverError(error);
        } finally {
            this.isProcessing = false;
            this.isAutoImporting = false;
        }
    }
}
