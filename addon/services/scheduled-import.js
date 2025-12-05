import Service from '@ember/service';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class ScheduledImportService extends Service {
    @service fetch;
    @service store;

    /**
     * Fetch all scheduled imports
     * 
     * @param {Object} params - Query parameters
     * @returns {Promise<Array>}
     */
    async query(params = {}) {
        return this.fetch.get('orders/scheduled-imports', params);
    }

    /**
     * Find a specific scheduled import
     * 
     * @param {String} id 
     * @returns {Promise<Object>}
     */
    async find(id) {
        return this.fetch.get(`orders/scheduled-imports/${id}`);
    }

    /**
     * Create a new scheduled import
     * 
     * @param {Object} data 
     * @returns {Promise<Object>}
     */
    async create(data) {
        return this.fetch.post('orders/scheduled-imports', data);
    }

    /**
     * Update a scheduled import
     * 
     * @param {String} id 
     * @param {Object} data 
     * @returns {Promise<Object>}
     */
    async update(id, data) {
        return this.fetch.put(`orders/scheduled-imports/${id}`, data);
    }

    /**
     * Delete a scheduled import
     * 
     * @param {String} id 
     * @returns {Promise<void>}
     */
    async delete(id) {
        return this.fetch.delete(`orders/scheduled-imports/${id}`);
    }
}
