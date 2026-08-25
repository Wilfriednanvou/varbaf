<?php

namespace Modules\Portail\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation du formulaire public de contact.
 *
 * `authorize()` rend `true` : la route est publique par construction, et
 * c'est la seule écriture que le portail autorise à un visiteur anonyme.
 *
 * Les bornes de longueur ne sont pas décoratives : elles sont la
 * première défense contre le remplissage automatique de la table par un
 * robot, avec le jeton CSRF du groupe `web`.
 */
class DemandeContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'min:2', 'max:120'],
            'contact' => ['required', 'string', 'min:5', 'max:150'],
            'sujet' => ['nullable', 'string', 'max:150'],
            'message' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nom' => 'nom',
            'contact' => 'téléphone ou adresse électronique',
            'sujet' => 'sujet',
            'message' => 'message',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => 'Le champ :attribute est obligatoire.',
            'min' => 'Le champ :attribute est trop court.',
            'max' => 'Le champ :attribute est trop long.',
        ];
    }
}
