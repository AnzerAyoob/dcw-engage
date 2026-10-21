const sectionsContainer = document.getElementById('sections_container');
const addFieldBtn = document.getElementById('add_field_btn');
const builderForm = document.getElementById('builderForm');
let fieldCounter = 0;

function createFieldCard(fieldData = null, targetContainer = null) {
    fieldCounter++;
    const card = document.createElement('div');
    card.className = 'field-card';
    card.id = `field_${fieldCounter}`;

    const label = fieldData ? fieldData.label : '';
    const type = fieldData ? fieldData.type : 'text';
    const requiredStr = fieldData && fieldData.required ? 'checked' : '';
    const optionsStr = fieldData && fieldData.options ? fieldData.options.join(', ') : '';

    card.innerHTML = `
        <div class="row">
            <div style="flex: 3;">
                <div class="label-toolbar" style="display:flex; gap:4px; margin-bottom:4px;">
                    <button type="button" class="btn-outline btn-sm label-fmt-btn" data-wrap="'''" title="Bold" style="font-weight:700; padding:2px 8px;">B</button>
                    <button type="button" class="btn-outline btn-sm label-fmt-btn" data-wrap="''" title="Italic" style="font-style:italic; padding:2px 8px;">I</button>
                    <button type="button" class="btn-outline btn-sm label-fmt-link" title="Link" style="padding:2px 8px;">Link</button>
                </div>
                <input type="text" class="field-title-input field-label" placeholder="Question Title (e.g. Full Name)" value="${label.replace(/"/g, '&quot;')}" required>
            </div>
            <div style="flex: 1;">
                <select class="field-type" onchange="toggleOptions(this, ${fieldCounter})">
                    <option value="text" ${type === 'text' ? 'selected' : ''}>Short Answer</option>
                    <option value="textarea" ${type === 'textarea' ? 'selected' : ''}>Paragraph</option>
                    <option value="email" ${type === 'email' ? 'selected' : ''}>Email Address</option>
                    <option value="select" ${type === 'select' ? 'selected' : ''}>Dropdown Menu</option>
                    <option value="checkbox" ${type === 'checkbox' ? 'selected' : ''}>Checkbox</option>
                    <option value="checkbox_group" ${type === 'checkbox_group' ? 'selected' : ''}>Multiple Choice (Checkboxes)</option>
                    <option value="file" ${type === 'file' ? 'selected' : ''}>File Upload</option>
                </select>
            </div>
        </div>

        <div class="options-wrapper" id="options_wrapper_${fieldCounter}" style="display: ${(type === 'select' || type === 'checkbox_group') ? 'block' : 'none'};">
            <label>Dropdown Options (comma separated)</label>
            <input type="text" class="field-options" placeholder="Option 1, Option 2, Option 3" value="${optionsStr.replace(/"/g, '&quot;')}">
        </div>

        <div class="row" style="margin-bottom:0; justify-content: space-between; border-top: 1px solid #f1f5f9; padding-top: 15px; margin-top: 15px;">
            <div class="toggle-wrapper">
                <input type="checkbox" class="field-required" id="req_${fieldCounter}" ${requiredStr}>
                <label for="req_${fieldCounter}" style="margin:0; cursor:pointer;">Required Question</label>
            </div>
            <div style="display:flex; gap:8px;">
                <button type="button" class="btn-outline btn-sm" title="Move up" onclick="moveField(${fieldCounter}, -1)">&uarr;</button>
                <button type="button" class="btn-outline btn-sm" title="Move down" onclick="moveField(${fieldCounter}, 1)">&darr;</button>
                <button type="button" class="btn-danger" onclick="removeField(${fieldCounter})">Trash</button>
            </div>
        </div>
    `;

    (targetContainer || document.querySelector('.section-fields')).appendChild(card);
}

// Toggle options visibility if select is chosen
window.toggleOptions = function (selectElement, id) {
    const wrapper = document.getElementById(`options_wrapper_${id}`);
    const showFor = ['select', 'checkbox_group'];
    wrapper.style.display = showFor.includes(selectElement.value) ? 'block' : 'none';
};

// Remove a field block
window.removeField = function (id) {
    document.getElementById(`field_${id}`).remove();
};

// Move a field card up (-1) or down (+1). Field order on submit is read
// straight off the DOM (see the submit handler below), so swapping the
// cards' position in fields_container is the whole fix — no separate
// "order" value to track or persist.
window.moveField = function (id, direction) {
    const card = document.getElementById(`field_${id}`);
    if (!card) return;
    const fieldsContainer = card.parentElement;

    if (direction === -1) {
        const prev = card.previousElementSibling;
        if (prev) fieldsContainer.insertBefore(card, prev);
    } else {
        const next = card.nextElementSibling;
        if (next) fieldsContainer.insertBefore(next, card);
    }
};

// --- Question label formatting toolbar (wikitext syntax, see #61) ---
// Same Bold/Italic/Link syntax as the description toolbar below, but
// deliberately without heading/indent/list — those are block-level
// constructs that don't make sense inside a single-line question label.
// Delegated on fieldsContainer since cards are added/removed dynamically,
// so one listener covers every card without rebinding per-card.
sectionsContainer.addEventListener('click', function(e) {
    const wrapBtn = e.target.closest('.label-fmt-btn');
    const linkBtn = e.target.closest('.label-fmt-link');
    if (!wrapBtn && !linkBtn) return;

    const card = e.target.closest('.field-card');
    const input = card.querySelector('.field-label');
    const start = input.selectionStart;
    const end = input.selectionEnd;
    const value = input.value;
    const selected = value.slice(start, end);

    let replacement, cursorOffset, selectLength;
    if (wrapBtn) {
        const marker = wrapBtn.dataset.wrap;
        replacement = marker + selected + marker;
        cursorOffset = marker.length;
        selectLength = selected.length;
    } else {
        const url = window.prompt('Link URL (http:// or https://):', 'https://');
        if (!url) return;
        const text = selected || 'link text';
        replacement = '[' + url + ' ' + text + ']';
        cursorOffset = replacement.length;
        selectLength = 0;
    }

    input.value = value.slice(0, start) + replacement + value.slice(end);
    input.focus();
    input.setSelectionRange(start + cursorOffset, start + cursorOffset + selectLength);
});
// --- Description formatting toolbar (wikitext syntax, see #44) ---
// Operates directly on #form_description's selection — it's a plain
// <textarea>, not contenteditable, so there's no execCommand to lean on.
const descriptionToolbar = document.getElementById('description_toolbar');
if (descriptionToolbar) {
    const descriptionTextarea = document.getElementById('form_description');

    const getLineBounds = function (value, pos) {
        const start = value.lastIndexOf('\n', pos - 1) + 1;
        let end = value.indexOf('\n', pos);
        if (end === -1) end = value.length;
        return { start: start, end: end };
    };

    // Replaces [start, end) with `replacement`, then places the cursor
    // (or a selection, if selectLength is given) at start + selectOffset.
    const replaceRange = function (start, end, replacement, selectOffset, selectLength) {
        const value = descriptionTextarea.value;
        descriptionTextarea.value = value.slice(0, start) + replacement + value.slice(end);
        const cursor = start + selectOffset;
        descriptionTextarea.focus();
        descriptionTextarea.setSelectionRange(cursor, cursor + (selectLength || 0));
    };

    const wrapSelection = function (marker) {
        const start = descriptionTextarea.selectionStart;
        const end = descriptionTextarea.selectionEnd;
        const selected = descriptionTextarea.value.slice(start, end);
        replaceRange(start, end, marker + selected + marker, marker.length, selected.length);
    };

    // Re-clicking a heading button on an already-headinged line swaps the
    // level instead of nesting a second pair of markers onto it.
    const toggleHeadingLine = function (marker) {
        const pos = descriptionTextarea.selectionStart;
        const bounds = getLineBounds(descriptionTextarea.value, pos);
        const line = descriptionTextarea.value.slice(bounds.start, bounds.end);
        const bare = line.replace(/^={2,3}\s+/, '').replace(/\s+={2,3}$/, '');
        const replacement = marker + ' ' + bare + ' ' + marker;
        replaceRange(bounds.start, bounds.end, replacement, marker.length + 1, bare.length);
    };

    const increaseIndent = function () {
        const pos = descriptionTextarea.selectionStart;
        const bounds = getLineBounds(descriptionTextarea.value, pos);
        const line = descriptionTextarea.value.slice(bounds.start, bounds.end);
        const existing = line.match(/^(:+)/);
        const level = Math.min((existing ? existing[1].length : 0) + 1, 3); // cap: #44 asks for up to level 3
        const rest = line.replace(/^:+\s*/, '');
        const replacement = ':'.repeat(level) + ' ' + rest;
        replaceRange(bounds.start, bounds.end, replacement, replacement.length, 0);
    };

    const insertLink = function () {
        const start = descriptionTextarea.selectionStart;
        const end = descriptionTextarea.selectionEnd;
        const url = window.prompt('Link URL (http:// or https://):', 'https://');
        if (!url) return;
        const text = descriptionTextarea.value.slice(start, end) || 'link text';
        const replacement = '[' + url + ' ' + text + ']';
        replaceRange(start, end, replacement, replacement.length, 0);
    };

    // Prefixes the current line with the list marker, e.g. '* ' or '# '
    // (see #47). Re-clicking the same marker removes it (toggle off);
    // clicking the other marker swaps it, same "one marker per line" rule
    // MiniWikiText::render() applies when grouping lines into a list.
    const toggleListLine = function (marker) {
        const pos = descriptionTextarea.selectionStart;
        const bounds = getLineBounds(descriptionTextarea.value, pos);
        const line = descriptionTextarea.value.slice(bounds.start, bounds.end);
        const existing = line.match(/^([*#])\s*/);
        const rest = line.replace(/^[*#]\s*/, '');
        const replacement = (existing && existing[1] === marker) ? rest : marker + ' ' + rest;
        replaceRange(bounds.start, bounds.end, replacement, replacement.length, 0);
    };

    descriptionToolbar.addEventListener('click', function (e) {
        const btn = e.target.closest('button');
        if (!btn) return;

        if (btn.dataset.wikiWrap) {
            wrapSelection(btn.dataset.wikiWrap);
        } else if (btn.dataset.wikiHeading) {
            toggleHeadingLine(btn.dataset.wikiHeading);
        } else if (btn.dataset.wikiIndent) {
            increaseIndent();
        } else if (btn.dataset.wikiLink) {
            insertLink();
        } else if (btn.dataset.wikiList) {
            toggleListLine(btn.dataset.wikiList);
        }
    });

    // Auto-grow the textarea to fit its content instead of a fixed height
    // with an inner scrollbar (see #47).
    const autoGrowDescription = function () {
        descriptionTextarea.style.height = 'auto';
        descriptionTextarea.style.height = descriptionTextarea.scrollHeight + 'px';
    };
    descriptionTextarea.addEventListener('input', autoGrowDescription);
    window.addEventListener('load', autoGrowDescription);
}

let sectionCounter = 0;
let slugEdited = false;

function createSection(sectionData = {}) {
    sectionCounter++;
    const section = document.createElement('section');
    section.className = 'form-section-card';
    section.dataset.sectionId = sectionData.id || ('section_' + sectionCounter);
    section.style.cssText = 'border:1px solid var(--border); border-radius:14px; padding:20px; margin:22px 0; background:var(--surface, #fff);';
    section.innerHTML = `
        <div class="row" style="justify-content:space-between; align-items:center;">
            <strong style="font-size:18px;">Section ${sectionCounter}</strong>
            <button type="button" class="btn-danger remove-section-btn">Remove section</button>
        </div>
        <label>Section title</label>
        <input type="text" class="section-title" placeholder="e.g. Personal Details" value="${String(sectionData.title || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;')}">
        <label>Section introduction (optional)</label>
        <textarea class="section-description" rows="2" placeholder="Briefly explain this section to applicants" style="width:100%; padding:12px; border:1.5px solid var(--border); border-radius:10px;">${String(sectionData.description || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')}</textarea>
        <div class="section-fields"></div>
        <button type="button" class="btn-outline btn-sm add-section-field" style="margin-top:12px;">+ Add Question to Section</button>
    `;
    sectionsContainer.appendChild(section);
    const fieldContainer = section.querySelector('.section-fields');
    if (Array.isArray(sectionData.fields) && sectionData.fields.length) {
        sectionData.fields.forEach(field => createFieldCard(field, fieldContainer));
    }
    return section;
}

sectionsContainer.addEventListener('click', function (e) {
    const add = e.target.closest('.add-section-field');
    if (add) {
        createFieldCard(null, add.closest('.form-section-card').querySelector('.section-fields'));
        return;
    }
    const remove = e.target.closest('.remove-section-btn');
    if (remove) {
        const all = sectionsContainer.querySelectorAll('.form-section-card');
        if (all.length <= 1) {
            window.alert('A form must have at least one section.');
            return;
        }
        remove.closest('.form-section-card').remove();
    }
});

if (typeof existingSchema !== 'undefined' && existingSchema) {
    document.getElementById('form_title').value = existingSchema.title || '';
    document.getElementById('form_description').value = existingSchema.description || '';
    document.getElementById('banner_image').value = existingSchema.banner_image || '';
    document.getElementById('form_type').value = typeof existingFormType !== 'undefined' ? existingFormType : '';
    slugEdited = true;

    if (Array.isArray(existingSchema.sections) && existingSchema.sections.length) {
        existingSchema.sections.forEach(sectionData => createSection(sectionData));
    } else {
        const section = createSection({title: '', description: ''});
        const container = section.querySelector('.section-fields');
        if (Array.isArray(existingSchema.fields) && existingSchema.fields.length) {
            existingSchema.fields.forEach(field => createFieldCard(field, container));
        } else {
            createFieldCard(null, container);
        }
    }
} else {
    const section = createSection({title: '', description: ''});
    createFieldCard(null, section.querySelector('.section-fields'));
}

if (addFieldBtn) {
    addFieldBtn.addEventListener('click', () => {
        const section = createSection({title: '', description: ''});
        section.querySelector('.section-title').focus();
        createFieldCard(null, section.querySelector('.section-fields'));
    });
}

const addSectionBtn = document.getElementById('add_section_btn');
if (addSectionBtn) {
    addSectionBtn.addEventListener('click', () => {
        const section = createSection({title: '', description: ''});
        section.querySelector('.section-title').focus();
    });
}

// Auto-generate URL Slug from Title
const formTypeInput = document.getElementById('form_type');
if (formTypeInput) {
    formTypeInput.addEventListener('input', function () {
        slugEdited = true;
    });
}

const formTitleInput = document.getElementById('form_title');
if (formTitleInput) {
    formTitleInput.addEventListener('input', function (e) {
        if (!slugEdited) {
            const title = e.target.value;
            const slug = title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
            if (formTypeInput) formTypeInput.value = slug;
        }
    });
}

// Reads the builder's current state (title/description/banner/fields) into
// the same schema shape the backend stores — shared by the real save
// (submit handler below) and the Preview button (see #47), so there's only
// one place that knows how to read a field card off the DOM.
function buildSchemaFromForm() {
    const title = document.getElementById('form_title').value;
    const description = document.getElementById('form_description').value;
    const bannerImage = document.getElementById('banner_image').value;

    const schema = {title, description, banner_image: bannerImage, sections: []};
    document.querySelectorAll('.form-section-card').forEach((section, sectionIndex) => {
        const sectionData = {
            id: section.dataset.sectionId || ('section_' + (sectionIndex + 1)),
            title: section.querySelector('.section-title').value.trim(),
            description: section.querySelector('.section-description').value.trim(),
            fields: []
        };
        section.querySelectorAll('.field-card').forEach(card => {
            const label = card.querySelector('.field-label').value;
            const type = card.querySelector('.field-type').value;
            const isRequired = card.querySelector('.field-required').checked;
            const name = label.toLowerCase().replace(/[^a-z0-9]/g, '_').replace(/_+/g, '_').replace(/^_|_$/g, '');
            const fieldData = {name: name || 'field_' + Math.floor(Math.random() * 1000), label, type, required: isRequired};
            if (type === 'select' || type === 'checkbox_group') {
                fieldData.options = card.querySelector('.field-options').value.split(',').map(opt => opt.trim()).filter(opt => opt.length > 0);
            }
            sectionData.fields.push(fieldData);
        });
        schema.sections.push(sectionData);
    });
    return schema;
}

// Compile JSON on submit
if (builderForm) {
    builderForm.addEventListener('submit', function (e) {
        e.preventDefault();
        document.getElementById('schema_json_input').value = JSON.stringify(buildSchemaFromForm(), null, 2);
        this.submit();
    });
}

// Preview button — opens the real public-form template in a new tab against
// the builder's current, unsaved schema (see #47).
const previewFormBtn = document.getElementById('preview_form_btn');
const previewForm = document.getElementById('previewForm');
if (previewFormBtn && previewForm) {
    previewFormBtn.addEventListener('click', function () {
        const schema = buildSchemaFromForm();
        if (!schema.sections.some(section => section.fields.length)) {
            window.alert('Add at least one question before previewing.');
            return;
        }
        document.getElementById('preview_schema_json_input').value = JSON.stringify(schema);
        previewForm.submit();
    });
}
