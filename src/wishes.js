for (const [toggleId, detailsId] of [
    ['toggle-recipient', 'recipient-details'],
    ['toggle-history', 'history-details'],
]) {
    const toggle = document.getElementById(toggleId);
    const details = document.getElementById(detailsId);

    if (toggle && details) {
        toggle.addEventListener('click', () => {
            const show = details.hidden;
            details.hidden = !show;
            toggle.textContent = show ? 'Hide' : 'Show';
            toggle.setAttribute('aria-expanded', String(show));
        });
    }
}

const editWishlist = document.getElementById('edit-wishlist');
const wishlistDisplay = document.getElementById('wishlist-display');
const wishlistForm = document.getElementById('wishlist-form');

if (editWishlist && wishlistDisplay && wishlistForm) {
    editWishlist.addEventListener('click', () => {
        wishlistDisplay.hidden = true;
        wishlistForm.hidden = false;
        editWishlist.setAttribute('aria-expanded', 'true');
        wishlistForm.querySelector('input[type="text"]')?.focus();
    });
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
