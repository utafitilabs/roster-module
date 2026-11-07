import { Controller } from '@hotwired/stimulus';

/*
 * A FILTER ROW THAT RE-ASKS THE SERVER. The row is a GET form; each of its
 * dropdowns is the shell's, which writes no input of its own, so the choice
 * is carried into the hidden field of the same name and the form is sent.
 * A filtered page stays an address somebody can send, and the counts the
 * options carry stay the server's, counted from the reading the rows came
 * from.
 *
 * The event and its detail are the shell's dropdown contract
 * (ShellBundle docs/dropdown.md): `uhifadhi--shell-bundle--dropdown:choose`
 * on the wrapper, bubbling, `detail: {value}`.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/API/HTMLFormElement/requestSubmit
 */
export default class extends Controller {
    chosen(event) {
        const wrapper = event.target.closest('[data-dd]');
        if (!wrapper || !(this.element instanceof HTMLFormElement)) {
            return;
        }

        const name = wrapper.dataset.dd;
        let field = this.element.querySelector(`input[type="hidden"][name="${name}"]`);
        if (!field) {
            field = document.createElement('input');
            field.type = 'hidden';
            field.name = name;
            this.element.appendChild(field);
        }

        const value = event.detail && event.detail.value != null ? String(event.detail.value) : '';
        field.value = value;
        field.disabled = '' === value;
        this.element.requestSubmit();
    }
}
