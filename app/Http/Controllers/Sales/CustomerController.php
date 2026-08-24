<?php

namespace App\Http\Controllers\Sales;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\ActiveTenantContext;
use App\Services\CustomerManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    public function index(Request $request, ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('viewAny', [Customer::class, $organization]);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', Rule::enum(CustomerStatus::class)]]);
        $customers = Customer::query()->where('organization_id', $organization->getKey())
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(fn ($query) => $query
                ->where('display_name', 'like', "%{$search}%")->orWhere('company_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderBy('display_name')->paginate(20)->withQueryString();

        return Inertia::render('Sales/Customers/Index', ['customers' => $customers, 'filters' => $filters]);
    }

    public function create(ActiveTenantContext $context): Response
    {
        $organization = $context->organizationOrFail();
        $this->authorize('create', [Customer::class, $organization]);

        return Inertia::render('Sales/Customers/Form', ['customer' => null]);
    }

    public function store(Request $request, ActiveTenantContext $context, CustomerManager $manager): RedirectResponse
    {
        $organization = $context->organizationOrFail();
        $this->authorize('create', [Customer::class, $organization]);
        $customer = $manager->create($request->user(), $organization, $request->validate($this->rules()));

        return redirect()->route('sales.customers.edit', $customer);
    }

    public function edit(Customer $customer): Response
    {
        $this->authorize('update', $customer);

        return Inertia::render('Sales/Customers/Form', ['customer' => $customer]);
    }

    public function update(Request $request, Customer $customer, CustomerManager $manager): RedirectResponse
    {
        $this->authorize('update', $customer);
        $manager->update($request->user(), $customer, $request->validate($this->rules()));

        return back();
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(CustomerType::class)], 'display_name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'], 'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'], 'tax_identifier' => ['nullable', 'string', 'max:128'],
            'billing_address' => ['nullable', 'string', 'max:5000'], 'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::enum(CustomerStatus::class)],
        ];
    }
}
