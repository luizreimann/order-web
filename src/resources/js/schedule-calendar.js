// Pinta o calendário. Não calcula vaga, antecedência nem folga.
// Checkout: lê o JSON do servidor e remonta no morph.updated.
// Admin: o mesmo widget só chama addOffDay. O POST do checkout não entra aqui.
import AirDatepicker from 'air-datepicker';
import 'air-datepicker/air-datepicker.css';
import localePt from 'air-datepicker/locale/pt';
import './schedule-calendar.css';

const locale = Array.isArray(localePt?.days) ? localePt : localePt?.default;

const CHECKOUT_MOUNT = '[data-schedule-calendar-mount]';
const OFF_DAY_MOUNT = '[data-schedule-off-day]';
const PAYLOAD = 'script[data-schedule-calendar]';

let booted = false;
let pending = false;

function calendarDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

function monthEnd(yearMonth) {
    const [year, month] = yearMonth.split('-').map(Number);
    const last = new Date(year, month, 0).getDate();

    return `${yearMonth}-${String(last).padStart(2, '0')}`;
}

function inViewMonth(date, viewDate) {
    return date.getFullYear() === viewDate.getFullYear() && date.getMonth() === viewDate.getMonth();
}

function wire(el) {
    const root = el.closest('[wire\\:id]');

    if (!root || !window.Livewire) {
        return null;
    }

    return window.Livewire.find(root.getAttribute('wire:id')) ?? null;
}

function readPayload(mount) {
    const scope = mount.closest('#scheduleSection') || mount.parentElement;
    const script = scope?.querySelector(PAYLOAD);

    if (!script) {
        return null;
    }

    try {
        return JSON.parse(script.textContent);
    } catch {
        return null;
    }
}

function reasonLabel(reason) {
    if (reason === 'off') {
        return 'Folga';
    }

    if (reason === 'full') {
        return 'Lotado';
    }

    return null;
}

function blockDisabledNav(datepicker) {
    // O next()/prev() da biblioteca não lê a classe. A seta desabilitada não muda o mês.
    datepicker.$datepicker.addEventListener('click', (event) => {
        const action = event.target.closest?.('.air-datepicker-nav--action');

        if (!action?.classList.contains('-disabled-')) {
            return;
        }

        event.stopPropagation();
        event.preventDefault();
    }, true);
}

function destroy(mount) {
    const current = mount._scheduleCalendar;

    mount._scheduleCalendar = null;

    if (current && !current.isDestroyed) {
        current.destroy();
    }

    mount.querySelectorAll('.air-datepicker').forEach((node) => node.remove());
}

function baseOptions() {
    return {
        inline: true,
        locale,
        // O locale pt começa na segunda. A semana da encomenda começa no domingo.
        firstDay: 0,
        classes: 'schedule-calendar',
        buttons: false,
        toggleSelected: false,
        showOtherMonths: true,
        selectOtherMonths: false,
        moveToOtherMonthsOnSelect: false,
        showOtherYears: true,
        selectOtherYears: false,
        moveToOtherYearsOnSelect: false,
    };
}

function mountCheckout(mount) {
    destroy(mount);

    const payload = readPayload(mount);

    if (!payload || payload.first_month == null || payload.last_month == null) {
        return;
    }

    const marked = wire(mount)?.get('scheduled_on');
    const selected = typeof marked === 'string' && payload.days?.[marked.trim()]?.state === 'open'
        ? marked.trim()
        : null;

    const datepicker = new AirDatepicker(mount, {
        ...baseOptions(),
        // Mês visível inteiro. O dia fora da janela continua na grade, desabilitado pelo payload.
        minDate: `${payload.first_month}-01`,
        maxDate: monthEnd(payload.last_month),
        disableNavWhenOutOfRange: true,
        startDate: selected || `${payload.first_month}-01`,
        selectedDates: selected ? [selected] : false,
        onBeforeSelect({ date, datepicker: picker }) {
            if (!inViewMonth(date, picker.viewDate)) {
                return false;
            }

            const day = payload.days[calendarDate(date)];

            return !!day && day.state === 'open';
        },
        onSelect({ date }) {
            if (!(date instanceof Date)) {
                return;
            }

            const key = calendarDate(date);
            const day = payload.days[key];

            if (!day || day.state !== 'open') {
                return;
            }

            const owner = wire(mount);

            if (!owner || owner.get('scheduled_on') === key) {
                return;
            }

            owner.set('scheduled_on', key);
        },
        onRenderCell({ date, cellType }) {
            if (cellType !== 'day') {
                return;
            }

            const day = payload.days[calendarDate(date)];

            if (day?.state === 'open') {
                return;
            }

            const label = reasonLabel(day?.reason);

            if (!label) {
                return { disabled: true };
            }

            return {
                disabled: true,
                classes: 'schedule-calendar-note',
                html: `${date.getDate()}<span class="schedule-calendar-note-label">${label}</span>`,
            };
        },
    });

    blockDisabledNav(datepicker);
    mount._scheduleCalendar = datepicker;
}

function mountOffDay(mount) {
    destroy(mount);

    const datepicker = new AirDatepicker(mount, {
        ...baseOptions(),
        onBeforeSelect({ date, datepicker: picker }) {
            return inViewMonth(date, picker.viewDate);
        },
        onSelect({ date }) {
            if (!(date instanceof Date)) {
                return;
            }

            wire(mount)?.call('addOffDay', calendarDate(date));
        },
    });

    mount._scheduleCalendar = datepicker;
}

function mountAll() {
    document.querySelectorAll(CHECKOUT_MOUNT).forEach(mountCheckout);
    document.querySelectorAll(OFF_DAY_MOUNT).forEach(mountOffDay);
}

function scheduleMount() {
    if (pending) {
        return;
    }

    pending = true;

    setTimeout(() => {
        pending = false;
        mountAll();
    }, 0);
}

function touchesCalendar(el) {
    if (!el || el.nodeType !== 1) {
        return false;
    }

    const selector = `${CHECKOUT_MOUNT}, ${OFF_DAY_MOUNT}, ${PAYLOAD}`;

    return el.matches(selector) || !!el.querySelector(selector);
}

function boot() {
    if (booted) {
        return;
    }

    booted = true;
    mountAll();
    window.Livewire.hook('morph.updated', ({ el }) => {
        if (touchesCalendar(el)) {
            scheduleMount();
        }
    });
}

document.addEventListener('livewire:initialized', boot);
document.addEventListener('livewire:navigated', scheduleMount);
