<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Books to SBA Reads</title>
    <style>
        body { font-family: sans-serif; margin: 20px; background-color: #f4f4f4; }
        .container { max-width: 900px; margin: auto; background-color: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1, h2 { text-align: center; color: #333; }
        .book-entry { border: 1px solid #ddd; padding: 15px; margin-bottom: 20px; border-radius: 5px; background-color: #f9f9f9; }
        .book-entry h3 { margin-top: 0; color: #555; }
        label { display: block; margin-bottom: 5px; font-weight: bold; color: #444; }
        input[type="text"], input[type="number"], input[type="date"], textarea {
            width: calc(100% - 22px);
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }
        input[type="file"] {
            margin-bottom: 15px;
        }
        /* Flex container for array inputs */
        .array-input {
            display: flex;
            flex-direction: column; /* Stack items vertically */
            gap: 10px; /* Space between items */
            margin-bottom: 15px;
            padding: 10px;
            border: 1px dashed #eee;
            border-radius: 4px;
            background-color: #fafafa;
        }
        /* Wrapper for each input + remove button */
        .array-input .input-item-wrapper {
            display: flex;
            align-items: center;
            gap: 5px; /* Space between input and button */
        }
        .array-input input {
            flex-grow: 1; /* Allow input to take available space */
            margin-bottom: 0; /* Remove default margin */
            width: auto; /* Override calc(100% - 22px) for these specific inputs */
        }
        button {
            background-color: #007bff;
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            margin-top: 10px;
            margin-right: 10px;
        }
        button:hover { background-color: #0056b3; }
        button.remove-book, button.remove-item {
            background-color: #dc3545;
            padding: 8px 12px; /* Make remove item buttons smaller */
            font-size: 0.9em;
        }
        button.remove-book:hover, button.remove-item:hover {
            background-color: #c82333;
        }
        #response {
            margin-top: 30px;
            padding: 20px;
            background-color: #e9ecef;
            border: 1px solid #ced4da;
            border-radius: 8px;
            white-space: pre-wrap;
            word-wrap: break-word;
            font-family: monospace;
            font-size: 0.9em;
        }
        .error-message {
            color: red;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .success-message {
            color: green;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .hidden { display: none; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Upload Books</h1>
        <p>This form sends a `POST` request to `https://sbareads.surprises.ng/api/books`.</p>
        <p class="error-message hidden" id="globalError"></p>
        <p class="success-message hidden" id="globalSuccess"></p>

        <form id="bookUploadForm">
            <div id="booksContainer">
                </div>

            <button type="button" id="addBookBtn">Add Another Book</button>
            <button type="submit">Submit Books</button>
        </form>

        <h2>API Response:</h2>
        <pre id="response"></pre>
    </div>

    <script>
        const API_URL = 'https://sba-reads-backend.test/api/books';
        const booksContainer = document.getElementById('booksContainer');
        const addBookBtn = document.getElementById('addBookBtn');
        const bookUploadForm = document.getElementById('bookUploadForm');
        const responseDisplay = document.getElementById('response');
        const globalError = document.getElementById('globalError');
        const globalSuccess = document.getElementById('globalSuccess');

        let bookIndex = 0;

        function showMessage(element, message, isError = false) {
            element.textContent = message;
            element.classList.remove('hidden');
            if (isError) {
                element.classList.add('error-message');
                element.classList.remove('success-message');
            } else {
                element.classList.add('success-message');
                element.classList.remove('error-message');
            }
        }

        function hideMessages() {
            globalError.classList.add('hidden');
            globalSuccess.classList.add('hidden');
            responseDisplay.textContent = '';
        }

        /**
         * Creates a dynamic input field for array-like data (e.g., Author IDs, Tags).
         * @param {string} fieldName - The base name for the input (e.g., 'authors', 'tags').
         * @param {number} bookIdx - The index of the current book.
         * @param {Array<string|number>} existingValues - Initial values to populate.
         * @param {string} inputType - The type of input ('text', 'number').
         * @param {string} placeholderText - Placeholder text for the input.
         * @param {string} addButtonText - Text for the add button.
         * @returns {HTMLElement} The container div for the array input.
         */
        function createArrayInput(fieldName, bookIdx, existingValues = [''], inputType = 'text', placeholderText = '', addButtonText = '') {
            const container = document.createElement('div');
            container.className = 'array-input';
            container.setAttribute('data-field', fieldName); // For easier targeting

            // Default placeholder and button text if not provided
            if (!placeholderText) {
                placeholderText = `Enter ${fieldName.replace(/_/g, ' ').replace('authors', 'Author ID')}`;
            }
            if (!addButtonText) {
                addButtonText = `+ Add ${fieldName.replace(/_/g, ' ').replace('authors', 'Author ID')}`;
            }

            const addFieldBtn = document.createElement('button');
            addFieldBtn.type = 'button';
            addFieldBtn.textContent = addButtonText;
            addFieldBtn.onclick = () => addInputField();

            // Append the addFieldBtn to the container FIRST
            container.appendChild(addFieldBtn);

            function addInputField(value = '') {
                const inputWrapper = document.createElement('div'); // Changed to div for better layout control
                inputWrapper.className = 'input-item-wrapper';

                const input = document.createElement('input');
                input.type = inputType;
                input.name = `books[${bookIdx}][${fieldName}][]`; // This naming is crucial for Laravel's array handling
                input.value = value;
                input.placeholder = placeholderText;

                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.textContent = 'x';
                removeBtn.className = 'remove-item';
                removeBtn.onclick = () => {
                    // Ensure we don't remove the last input field if it's the only one
                    if (container.querySelectorAll('.input-item-wrapper').length > 1) {
                        container.removeChild(inputWrapper);
                    } else {
                        input.value = ''; // Clear the value if it's the last one
                    }
                };

                inputWrapper.append(input, removeBtn);
                // Now, insertBefore will work because addFieldBtn is already a child
                container.insertBefore(inputWrapper, addFieldBtn);
            }

            existingValues.forEach(value => addInputField(value));
            return container;
        }


        function addBookEntry() {
            const currentBookIndex = bookIndex++;

            const bookDiv = document.createElement('div');
            bookDiv.className = 'book-entry';
            bookDiv.setAttribute('data-book-index', currentBookIndex);

            bookDiv.innerHTML = `
                <h3>Book #${currentBookIndex + 1}</h3>
                <label for="title-${currentBookIndex}">Title:</label>
                <input type="text" id="title-${currentBookIndex}" name="books[${currentBookIndex}][title]" value="Book Title ${currentBookIndex + 1}" required>

                <label for="sub_title-${currentBookIndex}">Sub Title:</label>
                <input type="text" id="sub_title-${currentBookIndex}" name="books[${currentBookIndex}][sub_title]" value="A Subtitle">

                <label for="description-${currentBookIndex}">Description:</label>
                <textarea id="description-${currentBookIndex}" name="books[${currentBookIndex}][description]" required>This is a detailed description for book ${currentBookIndex + 1}.</textarea>

                <label for="isbn-${currentBookIndex}">ISBN:</label>
                <input type="text" id="isbn-${currentBookIndex}" name="books[${currentBookIndex}][isbn]" value="978-0-1234-${Math.floor(Math.random() * 10000).toString().padStart(4, '0')}" required>

                <label>Main Author ID (Must Exist):</label>
                <input type="number" name="books[${currentBookIndex}][author_id]" value="3" required>

                <label>Additional Author IDs (Must Exist):</label>
                <div class="authors-input-${currentBookIndex}"></div>

                <label>Table of Contents (JSON array of objects):</label>
                <textarea name="books[${currentBookIndex}][table_of_contents]" required>[{"index":"Chapter 1","description":"Introduction"},{"index":"Chapter 2","description":"Details"}]</textarea>

                <label>Tags (comma-separated):</label>
                <div class="tags-input-${currentBookIndex}"></div>

                <label>Category IDs (comma-separated, Must Exist):</label>
                <div class="categories-input-${currentBookIndex}"></div>

                <label>Genres (comma-separated):</label>
                <div class="genres-input-${currentBookIndex}"></div>

                <label for="publication_date-${currentBookIndex}">Publication Date:</label>
                <input type="date" id="publication_date-${currentBookIndex}" name="books[${currentBookIndex}][publication_date]" value="2025-01-01">

                <label>Language (comma-separated):</label>
                <div class="language-input-${currentBookIndex}"></div>

                <label for="cover_image-${currentBookIndex}">Cover Image (JPG, PNG, PDF - Max 5MB):</label>
                <input type="file" id="cover_image-${currentBookIndex}" name="books[${currentBookIndex}][cover_image]" accept="image/jpeg,image/png,application/pdf" required>

                <label for="format-${currentBookIndex}">Format:</label>
                <input type="text" id="format-${currentBookIndex}" name="books[${currentBookIndex}][format]" value="Paperback">

                <label>Book Files (PDF, JPG, PNG - Max 5MB each. Hold Ctrl/Cmd to select multiple):</label>
                <input type="file" name="books[${currentBookIndex}][files][]" accept="image/jpeg,image/png,application/pdf" multiple required>

                <label>Target Audience (comma-separated):</label>
                <div class="target_audience-input-${currentBookIndex}"></div>

                <label for="actual_price-${currentBookIndex}">Actual Price:</label>
                <input type="number" step="0.01" id="actual_price-${currentBookIndex}" name="books[${currentBookIndex}][pricing][actual_price]" value="19.99" required>

                <label for="discounted_price-${currentBookIndex}">Discounted Price:</label>
                <input type="number" step="0.01" id="discounted_price-${currentBookIndex}" name="books[${currentBookIndex}][pricing][discounted_price]" value="14.99">

                <label for="currency-${currentBookIndex}">Currency:</label>
                <input type="text" id="currency-${currentBookIndex}" name="books[${currentBookIndex}][currency]" value="USD" maxlength="3">

                <label>Availability (comma-separated):</label>
                <div class="availability-input-${currentBookIndex}"></div>

                <label for="file_size-${currentBookIndex}">File Size:</label>
                <input type="text" id="file_size-${currentBookIndex}" name="books[${currentBookIndex}][file_size]" value="10MB">

                <label>DRM Info (JSON string, e.g., {"type":"None"}):</label>
                <textarea name="books[${currentBookIndex}][drm_info]">{"type":"None"}</textarea>

                <label for="pages-${currentBookIndex}">Pages (Meta Data):</label>
                <input type="number" id="pages-${currentBookIndex}" name="books[${currentBookIndex}][meta_data][pages]" value="300" required>

                <label for="publisher-${currentBookIndex}">Publisher:</label>
                <input type="text" id="publisher-${currentBookIndex}" name="books[${currentBookIndex}][publisher]" value="Example Publisher">

                <label for="archived-${currentBookIndex}">Archived:</label>
                <input type="checkbox" id="archived-${currentBookIndex}" name="books[${currentBookIndex}][archived]" value="1">

                <label for="deleted-${currentBookIndex}">Deleted:</label>
                <input type="checkbox" id="deleted-${currentBookIndex}" name="books[${currentBookIndex}][deleted]" value="1">

                <button type="button" class="remove-book">Remove Book</button>
            `;

            booksContainer.appendChild(bookDiv);

            // Initialize dynamic array inputs
            bookDiv.querySelector(`.authors-input-${currentBookIndex}`).appendChild(
                createArrayInput('authors', currentBookIndex, ['3'], 'number', 'Enter Author ID')
            );
            bookDiv.querySelector(`.tags-input-${currentBookIndex}`).appendChild(
                createArrayInput('tags', currentBookIndex, ['fiction', 'programming'], 'text', 'Enter Tag')
            );
            bookDiv.querySelector(`.categories-input-${currentBookIndex}`).appendChild(
                createArrayInput('categories', currentBookIndex, ['1', '2'], 'number', 'Enter Category ID')
            );
            bookDiv.querySelector(`.genres-input-${currentBookIndex}`).appendChild(
                createArrayInput('genres', currentBookIndex, ['Science Fiction', 'Fantasy'], 'text', 'Enter Genre')
            );
            bookDiv.querySelector(`.language-input-${currentBookIndex}`).appendChild(
                createArrayInput('language', currentBookIndex, ['English'], 'text', 'Enter Language')
            );
            bookDiv.querySelector(`.target_audience-input-${currentBookIndex}`).appendChild(
                createArrayInput('target_audience', currentBookIndex, ['Adults'], 'text', 'Enter Target Audience')
            );
             bookDiv.querySelector(`.availability-input-${currentBookIndex}`).appendChild(
                createArrayInput('availability', currentBookIndex, ['download'], 'text', 'Enter Availability (e.g., download, online)')
            );


            // Add event listener for remove button
            bookDiv.querySelector('.remove-book').onclick = () => {
                bookDiv.remove();
            };
        }

        addBookBtn.addEventListener('click', addBookEntry);
        addBookEntry(); // Add one book entry on initial load

        bookUploadForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            hideMessages();

            const formData = new FormData();
            let hasJsonError = false; // Flag to track JSON parsing errors

            // Iterate through each book entry
            document.querySelectorAll('.book-entry').forEach((bookDiv, bookIdx) => {
                if (hasJsonError) return; // Stop processing if a JSON error occurred

                // Collect inputs (excluding files and checkboxes for initial pass)
                bookDiv.querySelectorAll('input:not([type="file"]):not([type="checkbox"]), textarea').forEach(input => {
                    if (input.name) {
                        const fieldNameMatch = input.name.match(/books\[\d+\]\[(.*?)\]/);
                        if (fieldNameMatch) {
                            const fieldName = fieldNameMatch[1];
                            const fullInputName = input.name;

                            // Handle JSON fields (table_of_contents, drm_info)
                            if (fieldName === 'table_of_contents' || fieldName === 'drm_info') {
                                try {
                                    // Parse the input string from textarea
                                    const parsedValue = JSON.parse(input.value);
                                    // Stringify it back to send as a single string to FormData.
                                    // Laravel's 'json' validation rule will parse it on the backend.
                                    formData.append(fullInputName, JSON.stringify(parsedValue));
                                } catch (e) {
                                    console.error(`Invalid JSON for ${fieldName} in book ${bookIdx}:`, e);
                                    showMessage(globalError, `Invalid JSON for "${fieldName.replace(/_/g, ' ')}" in Book #${bookIdx + 1}. Please correct it.`, true);
                                    hasJsonError = true; // Set flag to prevent submission
                                    return; // Skip this input and potentially this book
                                }
                            }
                            // Handle simple array fields (tags, categories, etc.)
                            else if (fullInputName.includes('[]')) {
                                // These are already handled correctly by createArrayInput's naming,
                                // where each input has its own [] at the end of its name.
                                formData.append(fullInputName, input.value);
                            }
                            // Handle other simple text/number fields
                            else {
                                formData.append(fullInputName, input.value);
                            }
                        }
                    }
                });

                // Handle checkboxes (only send if checked, or '0' for unchecked)
                bookDiv.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                    formData.append(checkbox.name, checkbox.checked ? '1' : '0');
                });

                // Handle file inputs
                const coverImageInput = bookDiv.querySelector(`input[name="books[${bookIdx}][cover_image]"]`);
                if (coverImageInput && coverImageInput.files[0]) {
                    formData.append(`books[${bookIdx}][cover_image]`, coverImageInput.files[0]);
                }

                const filesInput = bookDiv.querySelector(`input[name="books[${bookIdx}][files][]"]`);
                if (filesInput && filesInput.files.length > 0) {
                    Array.from(filesInput.files).forEach(file => {
                        formData.append(`books[${bookIdx}][files][]`, file);
                    });
                }
            });

            // If any JSON parsing error occurred, stop the submission
            if (hasJsonError) {
                return;
            }

            // Log FormData contents for debugging (optional)
            console.log("--- FormData Contents ---");
            for (let pair of formData.entries()) {
                // For files, pair[1] will be a File object
                console.log(pair[0] + ': ', pair[1]);
            }
            console.log("------------------------");

            try {
                // Show a loading message
                responseDisplay.textContent = 'Sending request...';

                const response = await fetch(API_URL, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'Authorization': 'Bearer 16|9cXVboWc9Q12eTAGOfprTWyYc6vIDm8cTNprarCf16da100b',
                        'Accept': 'application/json'
                    }
                });

                console.log(response);

                const data = await response.json();

                if (!response.ok) {
                    showMessage(globalError, `Error ${response.status}: ${response.statusText}`, true);
                    responseDisplay.textContent = JSON.stringify(data, null, 2);
                    console.error('API Error:', data);
                } else {
                    showMessage(globalSuccess, 'Books uploaded successfully!', false);
                    responseDisplay.textContent = JSON.stringify(data, null, 2);
                    console.log('API Response:', data);
                }

            } catch (error) {
                showMessage(globalError, `Network Error: ${error.message}. Check console for details.`, true);
                responseDisplay.textContent = error.message;
                console.error('Fetch Error:', error);
            }
        });
    </script>
</body>
</html>
