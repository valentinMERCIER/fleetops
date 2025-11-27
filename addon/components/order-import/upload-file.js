import Component from '@glimmer/component';
import { action } from '@ember/object';

export default class OrderImportUploadFileComponent extends Component {
    @action
    onUpload(file) {
        if (this.args.onUpload) {
            this.args.onUpload(file);
        }
    }
}
