import { Controller } from '@hotwired/stimulus';
import { CardBound } from './bound_controller.js';

/*
 * THE SHEET IS BOUNDED TO WHAT IS LEFT OF THE SCREEN, by the rule every
 * bounded card shares — {@link CardBound} in `bound_controller.js`, which
 * measures the card's chrome and its place in the document and hands the
 * scroller `--cardmax`. The sheet spends that rule rather than a copy of it:
 * RULED 25 sep, the sheet, the day board, "here now", the agenda's day and
 * the roster under the live plate are one height.
 *
 * AND THE WINDOW OPENS ON TODAY. The sheet starts on a monday whatever day
 * somebody opens it, so at four weeks today's column can be most of a month
 * to the right; a planner should not have to go looking for the day they are
 * standing in.
 */
export default class extends Controller {
    static targets = ['scroller', 'today'];

    connect() {
        this.resize = () => this.size();
        window.addEventListener('resize', this.resize);
        this.size();
        this.showToday();
    }

    disconnect() {
        window.removeEventListener('resize', this.resize);
    }

    size() {
        if (!this.hasScrollerTarget) {
            return;
        }

        CardBound.apply(this.element, this.scrollerTarget);
    }

    /* Sideways only: the sheet opens at its first station, never scrolled
       past one. */
    showToday() {
        if (!this.hasScrollerTarget || !this.hasTodayTarget) {
            return;
        }

        const column = this.todayTarget.getBoundingClientRect();
        const port = this.scrollerTarget.getBoundingClientRect();

        if (column.left >= port.left && column.right <= port.right) {
            return;
        }

        this.scrollerTarget.scrollLeft += column.left - port.left - (port.width - column.width) / 2;
    }
}
