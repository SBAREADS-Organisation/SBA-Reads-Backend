<?php

namespace App\Http\Requests\Books;

use Illuminate\Foundation\Http\FormRequest;

class StoreBooksRequest extends FormRequest
{
    public function authorize(): bool
    {
        // you can add role‐based checks here
        // return auth()->check() && auth()->user()->hasAnyRole(['admin','author']);
        return true;
    }

    public function rules(): array
    {
        // dd($this->all());
        return [
            'books'                              => 'required|array|min:1',
            'books.*.title'                      => 'required|string|max:255',
            'books.*.sub_title'                  => 'nullable|string|max:255',
            'books.*.description'                => 'required|string',
            'books.*.author_id'                  => 'required|exists:users,id',
            'books.*.isbn'                       => 'required|string|unique:books,isbn',
            'books.*.table_of_contents'          => 'required|array',
            'books.*.tags'                       => 'nullable|array',
            'books.*.tags.*'                     => 'string|max:50',
            'books.*.category'                   => 'nullable|array',
            'books.*.category.*'                 => 'exists:categories,id',
            'books.*.genres'                     => 'nullable|array',
            'books.*.genres.*'                   => 'string|max:50',
            'books.*.publication_date'           => 'nullable|date',
            'books.*.language'                   => 'nullable|array',
            'books.*.language.*'                 => 'string|max:50',
            'books.*.cover_image.url'            => 'nullable|url',
            'books.*.cover_image.public_id'      => 'nullable|string',
            'books.*.format'                     => 'nullable|string|max:50',
            'books.*.files'                      => 'nullable|array',
            'books.*.files.*.url'                => 'required_with:books.*.files|url',
            'books.*.files.*.public_id'          => 'nullable|string',
            'books.*.target_audience'            => 'nullable|array',
            'books.*.target_audience.*'          => 'string|max:50',
            'books.*.pricing'                    => 'nullable|array',
            'books.*.pricing.actual_price'       => 'required_with:books.*.pricing|numeric|min:0',
            'books.*.pricing.discounted_price'   => 'nullable|numeric|min:0',
            'books.*.actual_price'               => 'nullable|numeric|min:0',
            'books.*.discounted_price'           => 'nullable|numeric|min:0',
            'books.*.currency'                   => 'nullable|string|size:3',
            'books.*.availability'               => 'nullable|array',
            'books.*.availability.*'             => 'string',
            'books.*.file_size'                  => 'nullable|string|max:50',
            'books.*.drm_info'                   => 'nullable|array',
            'books.*.meta_data'                  => 'nullable|array',
            'books.*.publisher'                  => 'nullable|string|max:255',
            'books.*.archived'                   => 'boolean',
            'books.*.deleted'                    => 'boolean',
        ];
    }
}
