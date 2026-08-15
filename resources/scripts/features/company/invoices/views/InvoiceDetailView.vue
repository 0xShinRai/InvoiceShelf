<template>
  <BasePage v-if="invoiceData" class="xl:pl-96 xl:ml-8">
    <BasePageHeader :title="pageTitle">
      <template #actions>
        <div class="text-sm mr-3">
          <BaseButton
            v-if="invoiceData.status === 'DRAFT' && canEdit"
            :disabled="isMarkAsSent"
            variant="primary-outline"
            @click="onMarkAsSent"
          >
            {{ $t('invoices.mark_as_sent') }}
          </BaseButton>
        </div>

        <BaseButton
          v-if="invoiceData.status === 'DRAFT' && canSend"
          variant="primary"
          class="text-sm mr-3"
          @click="onSendInvoice"
        >
          {{ $t('invoices.send_invoice') }}
        </BaseButton>

        <!-- Record Payment -->
        <router-link
          v-if="canCreatePayment"
          :to="`/admin/payments/${$route.params.id}/create`"
        >
          <BaseButton
            v-if="
              (invoiceData.status === 'SENT' ||
                invoiceData.status === 'VIEWED') &&
              invoiceData.due_amount > 0
            "
            variant="primary"
          >
            {{ $t('invoices.record_payment') }}
          </BaseButton>
        </router-link>

        <!-- Invoice Dropdown -->
        <InvoiceDropdown
          class="ml-3"
          :row="invoiceData"
          :load-data="refreshInvoiceList"
          :can-edit="canEdit"
          :can-view="canView"
          :can-create="canCreate"
          :can-delete="canDelete"
          :can-send="canSend"
          :can-create-payment="canCreatePayment"
          :can-create-estimate="canCreateEstimate"
        />
      </template>
    </BasePageHeader>

    <!-- Fallback warning: this invoice's data cannot produce valid e-invoice
         XML, so its PDF goes out as an ordinary one. Shown here, where the
         data can still be fixed. -->
    <BaseEInvoiceReadiness
      v-if="eInvoiceFallback"
      class="mb-4"
      :ready="false"
      :missing-requirements="eInvoiceReadiness?.missing_requirements ?? []"
      :warning-title="$t('e_invoice.fallback_title')"
      :warning-description="$t('e_invoice.fallback_desc')"
    />

    <!-- Credit note banner + link to the reversed invoice -->
    <div
      v-if="invoiceData.type === 'CREDIT_NOTE'"
      class="px-4 py-2 mb-4 text-sm rounded bg-red-50 text-red-700 border border-red-200"
    >
      <div class="flex items-center gap-2">
        <span class="px-2 py-0.5 text-xs font-semibold rounded bg-red-100">
          {{ $t('invoices.credit_note') }}
        </span>
        <span v-if="invoiceData.related_invoice">
          {{ $t('invoices.original_invoice') }}:
          <router-link
            :to="`/admin/invoices/${invoiceData.related_invoice.id}/view`"
            class="font-medium underline"
          >
            {{ invoiceData.related_invoice.invoice_number }}
          </router-link>
        </span>
      </div>
      <p v-if="invoiceData.credit_reason" class="mt-1 text-xs">
        {{ $t('invoices.credit_note_reason') }}: {{ invoiceData.credit_reason }}
      </p>
    </div>

    <!-- Credited banner + links to the reversing credit notes (mirror of the
         credit-note banner above, shown on the ORIGINAL invoice's side). A
         partial credit gets a softer headline than a full reversal, since the
         invoice is still live for the remainder. -->
    <div
      v-if="invoiceData.type !== 'CREDIT_NOTE' && isCredited"
      class="flex flex-wrap items-center gap-2 px-4 py-2 mb-4 text-sm rounded bg-amber-50 text-amber-800 border border-amber-300"
    >
      <span class="px-2 py-0.5 text-xs font-semibold rounded bg-amber-100">
        {{ isFullyCredited ? $t('invoices.cancelled') : $t('invoices.partially_credited') }}
      </span>
      <span>
        {{
          isFullyCredited
            ? $t('invoices.cancelled_via_credit_note')
            : $t('invoices.partially_credited_via_credit_notes')
        }}:
        <router-link
          v-for="creditNote in invoiceData.credit_notes"
          :key="creditNote.id"
          :to="`/admin/invoices/${creditNote.id}/view`"
          class="ml-1 font-medium underline"
        >
          {{ creditNote.invoice_number }}
        </router-link>
      </span>
      <span v-if="invoiceData.credited_total" class="font-medium">
        {{ $t('invoices.credited_amount') }}:
        <BaseFormatMoney
          :amount="invoiceData.credited_total"
          :currency="invoiceData.customer?.currency"
        />
      </span>
    </div>

    <BaseCard v-if="invoicePaymentAllocations.length" class="mb-4">
      <h2 class="mb-3 text-base font-semibold text-heading">{{ $t('invoices.allocated_payments') }}</h2>
      <div class="divide-y divide-line-light">
        <div v-for="allocation in invoicePaymentAllocations" :key="allocation.id" class="flex items-center justify-between gap-4 py-3 text-sm">
          <div>
            <router-link v-if="allocation.payment" :to="`/admin/payments/${allocation.payment.id}/view`" class="font-medium text-primary-500">
              {{ allocation.payment.payment_number }}
            </router-link>
            <span v-else class="font-medium text-heading">{{ $t('payments.payment') }}</span>
            <span v-if="allocation.payment?.formatted_payment_date" class="block mt-1 text-xs text-muted">{{ allocation.payment.formatted_payment_date }}</span>
          </div>
          <BaseFormatMoney :amount="allocation.amount" :currency="invoiceData.customer?.currency" class="font-medium text-heading" />
        </div>
      </div>
    </BaseCard>

    <!-- Sidebar -->
    <div
      class="fixed top-0 left-0 hidden h-full pt-16 pb-[6.4rem] ml-56 bg-surface xl:ml-64 w-88 xl:block"
    >
      <div
        class="flex items-center justify-between px-4 pt-8 pb-2 border border-line-default border-solid height-full"
      >
        <div class="mb-6">
          <BaseInput
            v-model="searchData.searchText"
            :placeholder="$t('general.search')"
            type="text"
            variant="gray"
            @input="onSearched()"
          >
            <template #right>
              <BaseIcon name="MagnifyingGlassIcon" class="h-5 text-subtle" />
            </template>
          </BaseInput>
        </div>

        <div class="flex mb-6 ml-3" role="group" aria-label="First group">
          <BaseDropdown class="ml-3" position="bottom-start">
            <template #activator>
              <BaseButton size="md" variant="gray">
                <BaseIcon name="FunnelIcon" />
              </BaseButton>
            </template>
            <div
              class="px-2 py-1 pb-2 mb-1 mb-2 text-sm border-b border-line-default border-solid"
            >
              {{ $t('general.sort_by') }}
            </div>

            <BaseDropdownItem class="flex px-1 py-2 cursor-pointer">
              <BaseInputGroup class="-mt-3 font-normal">
                <BaseRadio
                  id="filter_invoice_date"
                  v-model="searchData.orderByField"
                  :label="$t('reports.invoices.invoice_date')"
                  size="sm"
                  name="filter"
                  value="invoice_date"
                  @update:model-value="onSearched"
                />
              </BaseInputGroup>
            </BaseDropdownItem>

            <BaseDropdownItem class="flex px-1 py-2 cursor-pointer">
              <BaseInputGroup class="-mt-3 font-normal">
                <BaseRadio
                  id="filter_due_date"
                  v-model="searchData.orderByField"
                  :label="$t('invoices.due_date')"
                  value="due_date"
                  size="sm"
                  name="filter"
                  @update:model-value="onSearched"
                />
              </BaseInputGroup>
            </BaseDropdownItem>

            <BaseDropdownItem class="flex px-1 py-2 cursor-pointer">
              <BaseInputGroup class="-mt-3 font-normal">
                <BaseRadio
                  id="filter_invoice_number"
                  v-model="searchData.orderByField"
                  :label="$t('invoices.invoice_number')"
                  value="invoice_number"
                  size="sm"
                  name="filter"
                  @update:model-value="onSearched"
                />
              </BaseInputGroup>
            </BaseDropdownItem>
          </BaseDropdown>

          <BaseButton class="ml-1" size="md" variant="gray" @click="sortData">
            <BaseIcon v-if="getOrderBy" name="BarsArrowUpIcon" />
            <BaseIcon v-else name="BarsArrowDownIcon" />
          </BaseButton>
        </div>
      </div>

      <div
        ref="invoiceListSection"
        class="h-full overflow-y-scroll border-l border-line-default border-solid base-scroll"
      >
        <div v-for="(invoice, index) in invoiceList" :key="index">
          <router-link
            v-if="invoice"
            :id="'invoice-' + invoice.id"
            :to="`/admin/invoices/${invoice.id}/view`"
            :class="[
              'flex justify-between side-invoice p-4 cursor-pointer hover:bg-hover-strong items-center border-l-4 border-l-transparent',
              {
                'bg-surface-tertiary border-l-4 border-l-primary-500 border-solid':
                  hasActiveUrl(invoice.id),
              },
            ]"
            style="border-bottom: 1px solid rgba(185, 193, 209, 0.41)"
          >
            <div class="flex-2">
              <BaseText
                :text="invoice.customer?.name ?? ''"
                class="pr-2 mb-2 text-sm not-italic font-normal leading-5 text-heading capitalize truncate"
              />
              <div
                class="mt-1 mb-2 text-xs not-italic font-medium leading-5 text-body"
              >
                {{ invoice.invoice_number }}
              </div>
              <BaseEstimateStatusBadge
                :status="invoice.status"
                class="px-1 text-xs"
              >
                <BaseInvoiceStatusLabel :status="invoice.status" />
              </BaseEstimateStatusBadge>

              <!-- An invoice reversed by credit notes is cancelled (in full)
                   or partly credited: show a distinct badge so it's clear at a
                   glance in the list, mirroring InvoiceIndexView.vue's
                   cell-due_amount badges. -->
              <span
                v-if="invoice.type !== 'CREDIT_NOTE' && invoice.credited_status === 'FULL'"
                class="inline-block px-1 py-0.5 ml-1 text-xs font-medium rounded bg-amber-100 text-amber-800 whitespace-nowrap"
              >
                {{ $t('invoices.cancelled') }}
              </span>
              <span
                v-else-if="invoice.type !== 'CREDIT_NOTE' && invoice.credited_status === 'PARTIAL'"
                class="inline-block px-1 py-0.5 ml-1 text-[10px] font-medium rounded bg-amber-100 text-amber-800 whitespace-nowrap"
              >
                {{ $t('invoices.partially_credited') }}
              </span>
            </div>

            <div class="flex-1 whitespace-nowrap right">
              <BaseFormatMoney
                class="mb-2 text-xl not-italic font-semibold leading-8 text-right text-heading block"
                :amount="invoice.total"
                :currency="invoice.customer?.currency"
              />
              <div
                class="text-sm not-italic font-normal leading-5 text-right text-body est-date"
              >
                {{ invoice.formatted_invoice_date }}
              </div>
            </div>
          </router-link>
        </div>

        <div v-if="isLoading" class="flex justify-center p-4 items-center">
          <LoadingIcon class="h-6 m-1 animate-spin text-primary-400" />
        </div>
        <p
          v-if="!invoiceList?.length && !isLoading"
          class="flex justify-center px-4 mt-5 text-sm text-body"
        >
          {{ $t('invoices.no_matching_invoices') }}
        </p>
      </div>
    </div>

    <!-- PDF Preview -->
    <BasePdfPreview :src="shareableLink" />

    <SendInvoiceModal />
    <CreditNoteModal />
  </BasePage>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useInvoiceStore } from '../store'
import InvoiceDropdown from '../components/InvoiceDropdown.vue'
import SendInvoiceModal from '../components/SendInvoiceModal.vue'
import CreditNoteModal from '../components/CreditNoteModal.vue'
import LoadingIcon from '@/scripts/components/icons/LoadingIcon.vue'
import { useUserStore } from '../../../../stores/user.store'
import { useDialogStore } from '../../../../stores/dialog.store'
import { useModalStore } from '../../../../stores/modal.store'
import { useCompanyStore } from '@/scripts/stores/company.store'
import { useGlobalStore } from '@/scripts/stores/global.store'
import { einvoiceService } from '@/scripts/api/services/einvoice.service'
import type { InvoiceEInvoiceReadiness } from '@/scripts/api/services/einvoice.service'
import type { Invoice, InvoicePaymentAllocation } from '../../../../types/domain/invoice'

interface Props {
  canEdit?: boolean
  canView?: boolean
  canCreate?: boolean
  canDelete?: boolean
  canSend?: boolean
  canCreatePayment?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  canEdit: false,
  canView: false,
  canCreate: false,
  canDelete: false,
  canSend: false,
  canCreatePayment: false,
})

const ABILITIES = {
  EDIT: 'edit-invoice',
  VIEW: 'view-invoice',
  CREATE: 'create-invoice',
  DELETE: 'delete-invoice',
  SEND: 'send-invoice',
  CREATE_PAYMENT: 'create-payment',
} as const

const invoiceStore = useInvoiceStore()
const userStore = useUserStore()
const companyStore = useCompanyStore()
const globalStore = useGlobalStore()
const dialogStore = useDialogStore()
const modalStore = useModalStore()
const { t } = useI18n()
const route = useRoute()

const canEdit = computed<boolean>(() => {
  return props.canEdit || userStore.hasAbilities(ABILITIES.EDIT)
})

const canView = computed<boolean>(() => {
  return props.canView || userStore.hasAbilities(ABILITIES.VIEW)
})

const canCreate = computed<boolean>(() => {
  return props.canCreate || userStore.hasAbilities(ABILITIES.CREATE)
})

const canDelete = computed<boolean>(() => {
  return props.canDelete || userStore.hasAbilities(ABILITIES.DELETE)
})

const canSend = computed<boolean>(() => {
  return props.canSend || userStore.hasAbilities(ABILITIES.SEND)
})

const canCreatePayment = computed<boolean>(() => {
  return (
    props.canCreatePayment || userStore.hasAbilities(ABILITIES.CREATE_PAYMENT)
  )
})

const canCreateEstimate = computed<boolean>(() => {
  return userStore.hasAbilities('create-estimate')
})

const invoiceData = ref<Invoice | null>(null)
const isMarkAsSent = ref<boolean>(false)
const isLoading = ref<boolean>(false)

const invoiceList = ref<Invoice[] | null>(null)
const currentPageNumber = ref<number>(1)
const lastPageNumber = ref<number>(1)
const invoiceListSection = ref<HTMLElement | null>(null)

interface SearchData {
  orderBy: string | null
  orderByField: string | null
  searchText: string | null
}

const searchData = reactive<SearchData>({
  orderBy: null,
  orderByField: null,
  searchText: null,
})

const pageTitle = computed<string>(() => invoiceData.value?.invoice_number ?? '')

// credited_status is only emitted where the creditNotes relation was loaded,
// so fall back to the relation itself rather than hiding the banner outright.
const isCredited = computed<boolean>(() => {
  const status = invoiceData.value?.credited_status

  if (status) {
    return status !== 'NONE'
  }

  return !!invoiceData.value?.credit_notes?.length
})

const isFullyCredited = computed<boolean>(() => {
  return invoiceData.value?.credited_status !== 'PARTIAL'
})

const invoicePaymentAllocations = computed<InvoicePaymentAllocation[]>(() => invoiceData.value?.payment_allocations ?? [])

/**
 * Whether this invoice would trigger the Fallback: an ordinary PDF instead of
 * a Hybrid PDF, because its data cannot produce valid e-invoice XML. The server
 * decides it from the same check the PDF pipeline runs; a complete invoice
 * reports no fallback and so shows no warning.
 */
const eInvoiceReadiness = ref<InvoiceEInvoiceReadiness | null>(null)

const eInvoiceFallback = computed<boolean>(() => eInvoiceReadiness.value?.fallback === true)

/**
 * Only a company that issues e-invoices can fall back — its own switch and the
 * instance PDF driver both have to hold. The check is not even asked for
 * otherwise, since it builds the whole XML to answer.
 */
const issuesEInvoices = computed<boolean>(
  () =>
    globalStore.einvoice?.available === true &&
    companyStore.selectedCompanySettings.einvoice_enabled === 'YES'
)

async function loadEInvoiceReadiness(id: number): Promise<void> {
  if (!issuesEInvoices.value) {
    eInvoiceReadiness.value = null
    return
  }

  try {
    eInvoiceReadiness.value = await einvoiceService.invoiceReadiness(id)
  } catch {
    // Without a verdict there is nothing to warn about; the page stays whole.
    eInvoiceReadiness.value = null
  }
}

const getOrderBy = computed<boolean>(() => {
  return searchData.orderBy === 'asc' || searchData.orderBy === null
})

const shareableLink = computed<string>(() => {
  return `/invoices/pdf/${invoiceData.value?.unique_hash ?? ''}`
})

watch(route, (to) => {
  if (to.name === 'invoices.view') {
    loadInvoice()
  }
})

function onMarkAsSent(): void {
  dialogStore.openDialog({
    title: t('general.are_you_sure'),
    message: t('invoices.invoice_mark_as_sent'),
    yesLabel: t('general.ok'),
    noLabel: t('general.cancel'),
    variant: 'primary',
    hideNoButton: false,
    size: 'lg',
  }).then(async (res: boolean) => {
    if (res) {
      isMarkAsSent.value = false
      await invoiceStore.markAsSent({
        id: invoiceData.value!.id,
        status: 'SENT',
      })
      invoiceData.value!.status = 'SENT' as Invoice['status']
      isMarkAsSent.value = true
      isMarkAsSent.value = false
    }
  })
}

function onSendInvoice(): void {
  modalStore.openModal({
    title: t('invoices.send_invoice'),
    componentName: 'SendInvoiceModal',
    id: invoiceData.value!.id,
    data: invoiceData.value,
    refreshData: () => loadInvoice(),
  })
}

function hasActiveUrl(id: number): boolean {
  return Number(route.params.id) === id
}

async function loadInvoices(
  pageNumber?: number,
  fromScrollListener = false,
): Promise<void> {
  if (isLoading.value) return

  const params: Record<string, unknown> = {}

  if (searchData.searchText) {
    params.search = searchData.searchText
  }
  if (searchData.orderBy != null) {
    params.orderBy = searchData.orderBy
  }
  if (searchData.orderByField != null) {
    params.orderByField = searchData.orderByField
  }

  isLoading.value = true
  const response = await invoiceStore.fetchInvoices({
    page: pageNumber,
    ...params,
  } as never)
  isLoading.value = false

  invoiceList.value = invoiceList.value ?? []
  invoiceList.value = [...invoiceList.value, ...response.data.data]

  currentPageNumber.value = pageNumber ?? 1
  lastPageNumber.value = response.data.meta.last_page

  const invoiceFound = invoiceList.value.find(
    (inv) => inv.id === Number(route.params.id),
  )

  if (
    !fromScrollListener &&
    !invoiceFound &&
    currentPageNumber.value < lastPageNumber.value &&
    Object.keys(params).length === 0
  ) {
    loadInvoices(++currentPageNumber.value)
  }

  if (invoiceFound && !fromScrollListener) {
    setTimeout(() => scrollToInvoice(), 500)
  }
}

function scrollToInvoice(): void {
  const el = document.getElementById(`invoice-${route.params.id}`)
  if (el) {
    el.scrollIntoView({ behavior: 'smooth' })
    el.classList.add('shake')
    addScrollListener()
  }
}

function addScrollListener(): void {
  invoiceListSection.value?.addEventListener('scroll', (ev) => {
    const target = ev.target as HTMLElement
    if (
      target.scrollTop > 0 &&
      target.scrollTop + target.clientHeight > target.scrollHeight - 200
    ) {
      if (currentPageNumber.value < lastPageNumber.value) {
        loadInvoices(++currentPageNumber.value, true)
      }
    }
  })
}

async function loadInvoice(): Promise<void> {
  const id = Number(route.params.id)
  const response = await invoiceStore.fetchInvoice(id)
  if (response.data) {
    invoiceData.value = { ...response.data.data } as Invoice
  }

  await loadEInvoiceReadiness(id)
}

let searchTimeout: ReturnType<typeof setTimeout> | null = null

function onSearched(): void {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    invoiceList.value = []
    loadInvoices()
  }, 500)
}

// Reset-and-refetch the sidebar list from page 1. Used after actions that
// change which invoices exist or their status (e.g. creating a credit
// note), since `loadInvoices()` alone only appends (it's built for
// infinite-scroll pagination) and would duplicate already-loaded rows.
function refreshInvoiceList(): void {
  invoiceList.value = []
  loadInvoices()
}

function sortData(): void {
  if (searchData.orderBy === 'asc') {
    searchData.orderBy = 'desc'
  } else {
    searchData.orderBy = 'asc'
  }
  onSearched()
}

// Initialize
loadInvoices()
loadInvoice()
</script>
