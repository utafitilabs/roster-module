import { Controller } from '@hotwired/stimulus';

/*
 * A BOUNDED CARD — ONE HEIGHT RULE FOR EVERY CARD THAT SCROLLS INSIDE ITSELF.
 *
 * RULED 25 sep: the week sheet, the day board, "here now" beside it, the day
 * on the agenda and the roster under the live plate are one height, and they
 * never grow with their data. A day board of thirty-two posts was a 3,416px
 * page with the line at "now" off the bottom of it.
 *
 * CSS cannot say "the viewport, less everything above me", so the one number
 * it is missing is measured here and handed back as `--cardmax`, which the
 * shared `.rscroll` rule in roster.css spends. The bound sits on the SCROLLER
 * so a head that has wrapped to three lines at 400px is never cut.
 *
 * THE CHROME IS MEASURED, NOT ASSUMED: `card.offsetHeight -
 * scroller.offsetHeight` is the head, the padding and whatever else the card
 * carries, on this screen.
 *
 * AND THE SPACE IS READ FROM THE CARD'S PLACE IN THE DOCUMENT, not from where
 * the screen happens to be pointing, so the height is a function of the
 * layout and the viewport — recomputed on RESIZE and never on scroll.
 */
export default class extends Controller {
    static targets = ['scroller'];

    connect() {
        this.resize = () => this.size();
        window.addEventListener('resize', this.resize);
        this.size();
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
}

/*
 * THE RULE, KEPT OUT OF THE CONTROLLER so the week sheet's own controller
 * spends the same numbers rather than a copy of them.
 *
 * THE SHEET'S SHAPE AT FOUR FIFTHS. RULED 21 sep the sheet grew by 30 %: a
 * 670px card floor, and what the screen leaves below the card taken at 1.3,
 * capped at one viewport less the card's chrome. RULED 25 sep every bounded
 * card is 20 % shorter than that: the floor is 536px and the measured card
 * is taken at 0.8.
 */
export const CardBound = {
    /* The shortest a bounded card is: the sheet's 670px, less a fifth. */
    CARD_FLOOR: 536,

    /* Below this the scroller stops being a list and starts being a slot:
       the sheet's 300px, less a fifth. */
    SCROLLER_FLOOR: 240,

    /* What the screen leaves below the card, taken at the sheet's 1.3. */
    GROW: 1.3,

    /* And the card at four fifths of what that made it. */
    SHARE: 0.8,

    /**
     * The scroller's bound, in px, from the viewport's height, the card's
     * top in the document and the card's own chrome.
     */
    scrollerMax({ viewport, top, chrome }) {
        const free = viewport - Math.min(Math.max(top, 0), viewport) - chrome;
        const cap = viewport - chrome;
        const grown = Math.min(free * CardBound.GROW, cap);
        const floor = Math.max(CardBound.SCROLLER_FLOOR, CardBound.CARD_FLOOR - chrome);

        return Math.round(Math.max(floor, (chrome + grown) * CardBound.SHARE - chrome));
    },

    /** Measure the card and hand its scroller the bound. */
    apply(card, scroller) {
        const max = CardBound.scrollerMax({
            viewport: window.innerHeight,
            // The card's top in the DOCUMENT, so the answer does not move
            // with the scroll position.
            top: card.getBoundingClientRect().top + window.scrollY,
            // Everything of the card that is not the scroller, measured.
            chrome: card.offsetHeight - scroller.offsetHeight,
        });

        scroller.style.setProperty('--cardmax', `${max}px`);

        return max;
    },
};
