import { Controller } from '@hotwired/stimulus';

/*
 * WHERE "NOW" IS ON THE DAY BOARD — placed from the VIEWER'S clock, and
 * placed again every minute.
 *
 * THE SERVER CANNOT ANSWER THIS, and that is the whole reason the controller
 * exists rather than a `style="left:…"` rendered in Twig. Three things were
 * wrong with the server-rendered line, and only the third is obvious:
 *
 *   1. IT WAS THE SERVER'S INSTANT, in the SERVER'S timezone. The ruling is
 *      that the viewer's clock is the authority for every instant in the
 *      product; a box in UTC drawing a line for somebody in UTC+3 is three
 *      hours wrong and looks deliberate.
 *   2. IT WAS THE SERVER'S IDEA OF WHICH DAY IT IS. Whether to draw the line
 *      at all was decided by comparing dates in the server's zone, so for
 *      the hours around midnight the line appeared on the wrong day.
 *   3. IT WAS FROZEN AT RENDER. The position never moved again. This board
 *      is explicitly designed for "a screen on an office wall", which is a
 *      page nobody reloads — so by the afternoon the line was wherever the
 *      morning had left it. This is the defect somebody actually reported.
 *
 * SO: the element is rendered with the DAY it belongs to and nothing else,
 * and the browser — which is the only thing that knows what time it is where
 * the reader is — decides both the position and whether there is a line to
 * draw at all.
 *
 * IT TICKS ON THE MINUTE, not every sixty seconds from whenever it loaded, so
 * the line moves when the clock does and two boards side by side agree.
 *
 * THE LINE STAYS IN THE MIDDLE AND THE DAY MOVES UNDER IT — RULED 25 sep.
 * The hours are wider than the window, so a line that walked across them
 * walked out of sight. The line is still drawn at now's place on the hours,
 * and the SCROLLER is moved so that place sits at the centre of the window
 * the pinned post names leave: every minute the day slides one minute left
 * under a line that does not move. At the two ends of the day the scroller
 * can go no further, and the line sits left of centre just after midnight
 * and right of it just before — which is correct.
 *
 * A READER WHO SCROLLS BY HAND IS NOT FOUGHT. A horizontal move the
 * controller did not make marks the board as the reader's, and the minute
 * then moves the line and not the board — until the viewer's day changes,
 * which hands the board back to the clock. A resize changes the geometry the
 * reader chose a position in, so it re-centres.
 */
export default class extends Controller {
    static targets = ['scroller', 'axis', 'line'];

    static values = {
        /** The day this board is drawing, as YYYY-MM-DD in the AREA's own calendar. */
        day: String,
    };

    connect() {
        this.userScrolled = false;
        this.today = NowLine.localDate(new Date());
        this.left = this.hasScrollerTarget ? this.scrollerTarget.scrollLeft : 0;
        this.width = this.hasScrollerTarget ? this.scrollerTarget.clientWidth : 0;

        this.place({ follow: true });
        this.tick();

        if (this.hasScrollerTarget && 'ResizeObserver' in window) {
            this.observer = new ResizeObserver(() => this.resized());
            this.observer.observe(this.scrollerTarget);
        }
    }

    disconnect() {
        clearTimeout(this.timer);
        this.observer?.disconnect();
    }

    /**
     * THE LINE, OR NO LINE. A day that is not the viewer's today has no "now"
     * on it: drawing one would put this minute on a day nobody is standing.
     */
    place({ follow }) {
        const now = new Date();
        const today = NowLine.localDate(now);

        if (today !== this.today) {
            this.today = today;
            this.userScrolled = false;
        }

        if (!this.hasLineTarget) {
            return;
        }

        if (this.dayValue !== today) {
            this.lineTarget.hidden = true;

            return;
        }

        const percent = NowLine.percentOfDay(now);

        this.lineTarget.hidden = false;
        this.lineTarget.style.left = `${percent}%`;

        if (follow && !this.userScrolled) {
            this.centre(percent);
        }
    }

    /** Move the hours so `percent` of the day sits at the centre of the window. */
    centre(percent) {
        if (!this.hasScrollerTarget || !this.hasAxisTarget) {
            return;
        }

        const port = this.scrollerTarget.getBoundingClientRect();
        const axis = this.axisTarget.getBoundingClientRect();

        this.scrollerTarget.scrollLeft = NowLine.centredScrollLeft({
            percent,
            // Where the hours begin, in the scroller's own coordinates.
            axisStart: axis.left - port.left - this.scrollerTarget.clientLeft + this.scrollerTarget.scrollLeft,
            axisWidth: axis.width,
            viewportWidth: this.scrollerTarget.clientWidth,
            scrollWidth: this.scrollerTarget.scrollWidth,
        });

        // What the browser actually set, clamped and rounded — the scroll
        // event this causes then reads as the controller's, not the reader's.
        this.left = this.scrollerTarget.scrollLeft;
    }

    /** A scroll the controller did not make is the reader taking the board. */
    scrolled() {
        const left = this.scrollerTarget.scrollLeft;

        if (Math.abs(left - this.left) > 1) {
            this.userScrolled = true;
        }

        this.left = left;
    }

    /** A new width is a new geometry: re-centre. A new height is not. */
    resized() {
        const width = this.scrollerTarget.clientWidth;

        if (width === this.width) {
            return;
        }

        this.width = width;
        this.userScrolled = false;
        this.place({ follow: true });
    }

    /** Re-place on the next minute boundary, then every minute after it. */
    tick() {
        const msToNextMinute = 60000 - (Date.now() % 60000);

        this.timer = setTimeout(() => {
            this.place({ follow: true });
            this.tick();
        }, msToNextMinute);
    }
}

/*
 * THE MATHS, KEPT OUT OF THE CONTROLLER so it can be read — and compared with
 * the server's own percent-of-a-day, which places the blocks the line runs
 * over. The two have to agree or the line lands between the right hours.
 */
export const NowLine = {
    /** How far through the LOCAL day an instant is, as a percentage. */
    percentOfDay(at) {
        return ((at.getHours() * 60 + at.getMinutes()) / (24 * 60)) * 100;
    },

    /**
     * WHERE THE SCROLLER HAS TO BE for `percent` of the day to sit at the
     * centre of the window. The post names are pinned over the first
     * `axisStart` pixels of the scroller, so the window the hours show is
     * what is left to the right of them, and its centre is half of that.
     * Clamped to what the scroller can do: at the two ends of the day now
     * cannot be centred, and the line then sits off-centre.
     */
    centredScrollLeft({ percent, axisStart, axisWidth, viewportWidth, scrollWidth }) {
        const x = axisStart + axisWidth * percent / 100;
        const centre = axisStart + (viewportWidth - axisStart) / 2;

        return Math.min(Math.max(0, x - centre), Math.max(0, scrollWidth - viewportWidth));
    },

    /** The viewer's own calendar date, which is not necessarily the server's. */
    localDate(at) {
        return [
            at.getFullYear(),
            String(at.getMonth() + 1).padStart(2, '0'),
            String(at.getDate()).padStart(2, '0'),
        ].join('-');
    },
};
