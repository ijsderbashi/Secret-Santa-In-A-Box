const recipientToggle = document.getElementById('toggle-recipient');
const recipientDetails = document.getElementById('recipient-details');

if (recipientToggle && recipientDetails) {
    function hideRecipient() {
        recipientDetails.hidden = true;
        recipientToggle.textContent = 'Show';
        recipientToggle.setAttribute('aria-expanded', 'false');
    }

    recipientToggle.addEventListener('click', () => {
        const show = recipientDetails.hidden;
        recipientDetails.hidden = !show;
        recipientToggle.textContent = show ? 'Hide' : 'Show';
        recipientToggle.setAttribute('aria-expanded', String(show));
    });

    // Returning through browser history should not reveal the recipient either.
    window.addEventListener('pageshow', hideRecipient);
}

const wishItems = document.getElementById('wish-items');

if (wishItems) {
    function ensureEmptyLastRow() {
        const lastInput = wishItems.lastElementChild?.querySelector('input');
        if (lastInput && lastInput.value === '') return;

        const index = wishItems.children.length;
        const row = document.createElement('div');
        row.className = 'wish-row';

        const label = document.createElement('label');
        label.htmlFor = `wish_item_${index}`;
        label.textContent = `Wish Item #${index + 1}`;

        const input = document.createElement('input');
        input.type = 'text';
        input.name = 'wish_items[]';
        input.id = label.htmlFor;
        input.maxLength = 255;

        row.append(label, input);
        wishItems.append(row);
    }

    wishItems.addEventListener('input', ensureEmptyLastRow);
    window.addEventListener('pageshow', ensureEmptyLastRow);
    ensureEmptyLastRow();
}
