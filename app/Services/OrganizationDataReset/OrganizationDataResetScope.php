<?php

namespace App\Services\OrganizationDataReset;

enum OrganizationDataResetScope: string
{
    case SalesAndDocuments = 'sales_and_documents';
    case TransactionalFinance = 'transactional_finance';
    case CatalogAndStock = 'catalog_and_stock';
    case Customers = 'customers';
    case SuppliersAndPurchasing = 'suppliers_and_purchasing';
    case UsersAndAccess = 'users_and_access';
    case FullOrganization = 'full_organization';

    /** @return list<self> */
    public function expanded(): array
    {
        if ($this !== self::FullOrganization) {
            return [$this];
        }

        return [
            self::SalesAndDocuments,
            self::TransactionalFinance,
            self::CatalogAndStock,
            self::Customers,
            self::SuppliersAndPurchasing,
            self::UsersAndAccess,
        ];
    }
}
