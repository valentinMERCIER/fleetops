import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class OrderImportScheduleSettingsComponent extends Component {
    @tracked startDate = new Date().toISOString().split('T')[0];
    @tracked frequency = 1;
    @tracked period = 'Week';
    @tracked days = [];
    @tracked time = '09:00';
    @tracked timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
    @tracked ends = 'never';
    @tracked endDate = '';
    @tracked occurrences = 1;

    get name() {
        return this.args.name || 'New Scheduled Import';
    }

    set name(value) {
        // Name is controlled by the parent component
        if (this.args.onNameChange) {
            this.args.onNameChange(value);
        }
    }


    get periods() {
        return ['Once', 'Day', 'Week', 'Month', 'Year'];
    }

    get isOnce() {
        return this.period === 'Once';
    }

    get minDate() {
        // Return today's date in YYYY-MM-DD format
        return new Date().toISOString().split('T')[0];
    }

    get daysOfWeek() {
        return ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    }

    @action
    isDaySelected(day) {
        return this.days.includes(day);
    }

    @action
    toggleDay(day) {
        if (this.days.includes(day)) {
            this.days = this.days.filter(d => d !== day);
        } else {
            this.days = [...this.days, day];
        }
    }

    @action
    updateTime(event) {
        this.time = event.target.value;
    }

    @action
    updatePeriod(event) {
        this.period = event.target.value;
        // If 'Once' is selected, set frequency to 1
        if (this.period === 'Once') {
            this.frequency = 1;
        }
    }

    @action
    handleNameChange(event) {
        const newName = event.target.value;
        this.name = newName;
        if (this.args.onNameChange) {
            this.args.onNameChange(newName);
        }
    }

    @action
    updateEndDate(event) {
        this.endDate = event.target.value;
    }

    @action
    updateOccurrences(event) {
        this.occurrences = parseInt(event.target.value) || 1;
    }
}
