<?php

namespace Kukux\DigitalSignature\Hub\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Kukux\DigitalSignature\Hub\OAuth\AuthenticateHubToken;
use Kukux\DigitalSignature\Hub\Personnel;
use Kukux\DigitalSignature\Models\HubHolder;

/**
 * Who is who, for apps (docs/hub/contracts.md §2.2):
 *
 *   GET  people?email=… | ?emp_no=…   exact match, at most one result
 *   GET  people/{sub}
 *   POST people/{sub}/link             the app names this person as a signatory
 *
 * Lookup is exact-match only, never a search: an app has no business
 * listing the directory (R9).
 */
class PeopleController extends Controller
{
    use ValidatesInput;

    public function __construct(private readonly People $people) {}

    public function lookup(Request $request): JsonResponse
    {
        $input = $this->validated($request, [
            'email'  => ['required_without:emp_no', 'prohibits:emp_no', 'string', 'email:rfc', 'max:255'],
            'emp_no' => ['required_without:email', 'string', 'max:64', 'regex:/^[A-Za-z0-9._\-]+$/'],
        ], $request->query());

        $person = isset($input['email'])
            ? $this->people->byEmail($input['email'])
            : $this->people->byEmpNo($input['emp_no']);

        return response()->json([
            'data' => $person !== null ? [$this->people->claims($person)] : [],
        ]);
    }

    public function show(string $sub): JsonResponse
    {
        $person = $this->person($sub);

        return response()->json($this->people->claims($person) + ['active' => $person->active]);
    }

    public function link(Request $request, string $sub): JsonResponse
    {
        $this->person($sub);

        HubHolder::link(AuthenticateHubToken::app($request)->id, $sub);

        return response()->json(['linked' => true]);
    }

    /** @throws HubApiException */
    private function person(string $sub): Personnel
    {
        return $this->people->find($sub)
            ?? throw new HubApiException(404, 'unknown_person', 'No person with that sub.');
    }
}
