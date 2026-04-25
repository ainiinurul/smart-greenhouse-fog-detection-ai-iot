<script setup>
import { computed, ref, onMounted, onUnmounted } from "vue";
import { Head, usePage } from "@inertiajs/vue3";
import BreezeAuthenticatedLayout from "@/Layouts/Authenticated.vue";
import VueDatePicker from "@vuepic/vue-datepicker";
import "@vuepic/vue-datepicker/dist/main.css";
import DigitalClock from "@/Components/DigitalClock.vue";
import Button from "@/Components/Button.vue";
import { AgGridVue } from "ag-grid-vue3";
import { AllCommunityModule, ModuleRegistry, themeAlpine } from "ag-grid-community";
import { useToast } from "vue-toastification";
import { useLocale } from "@/composables/useLocale";

ModuleRegistry.registerModules([AllCommunityModule]);

const toast = useToast();
const { t, locale } = useLocale();

const page = usePage();
const { greenhouses, latestData } = page.props;
const actuatorStatus = computed(() => page.props.actuatorStatus || {});
const daterange = ref();
const isExporting = ref(false);
const selectedGreenhouse = ref("");
const rowImageLoading = ref({});
const rowTableLoading = ref({});

const activeActuatorGhId = computed(() => {
    const parsedSelected = Number(selectedGreenhouse.value);
    if (Number.isFinite(parsedSelected) && parsedSelected > 0) {
        return parsedSelected;
    }

    const fallback = Number(greenhouses?.[0]?.id);
    return Number.isFinite(fallback) && fallback > 0 ? fallback : 1;
});

const actuators = computed(() => {
    const currentStatus =
        actuatorStatus.value?.[activeActuatorGhId.value] ||
        actuatorStatus.value?.[String(activeActuatorGhId.value)] ||
        {};

    return [
        {
            key: "exhaust",
            name: t("monitoring.exhaust_fan"),
            icon: "fas fa-fan",
            color: "text-yellow-500",
            status: Boolean(currentStatus.exhaust?.status),
            gatewayOnline: Boolean(currentStatus.exhaust?.gateway_online),
        },
        {
            key: "dehumidifier",
            name: t("monitoring.dehumidifier"),
            icon: "fas fa-tint",
            color: "text-cyan-500",
            status: Boolean(currentStatus.dehumidifier?.status),
            gatewayOnline: Boolean(currentStatus.dehumidifier?.gateway_online),
        },
        {
            key: "blower",
            name: t("monitoring.blower"),
            icon: "fas fa-fan",
            color: "text-red-500",
            status: Boolean(currentStatus.blower?.status),
            gatewayOnline: Boolean(currentStatus.blower?.gateway_online),
        },
    ];
});

const getActuatorIconAnimationClass = (actuator) => {
    if (!actuator?.status) {
        return "";
    }

    if (actuator.key === "dehumidifier") {
        return "actuator-dehumidifier-active";
    }

    return "actuator-fan-active";
};

const rowSelectionConfig = {
    mode: "singleRow",
    checkboxes: false,
    enableClickSelection: true,
};

// column
const formatCameraDateTime = (rawValue) => {
    if (rawValue === null || rawValue === undefined || rawValue === "") {
        return "-";
    }

    const value = String(rawValue).trim();
    const slashFormatMatch = value.match(
        /^(\d{2})\/(\d{2})\/(\d{4})\s(\d{1,2}):(\d{2}):(\d{2})(?:\s?(AM|PM))?$/i
    );
    if (slashFormatMatch) {
        const [, day, month, year, hourRaw, minute, second, meridiemRaw] =
            slashFormatMatch;
        let hour = Number(hourRaw);
        const meridiem = meridiemRaw ? meridiemRaw.toUpperCase() : null;
        if (meridiem === "PM" && hour < 12) {
            hour += 12;
        }
        if (meridiem === "AM" && hour === 12) {
            hour = 0;
        }

        return `${day}/${month}/${String(year).slice(-2)} ${String(hour).padStart(
            2,
            "0"
        )}:${minute}`;
    }

    const isoLikeValue = value.includes("T") ? value : value.replace(" ", "T");
    const parsedDate = new Date(isoLikeValue);
    if (Number.isNaN(parsedDate.getTime())) {
        return value;
    }

    const day = String(parsedDate.getDate()).padStart(2, "0");
    const month = String(parsedDate.getMonth() + 1).padStart(2, "0");
    const year = String(parsedDate.getFullYear()).slice(-2);
    const hour = String(parsedDate.getHours()).padStart(2, "0");
    const minute = String(parsedDate.getMinutes()).padStart(2, "0");
    return `${day}/${month}/${year} ${hour}:${minute}`;
};

const columnDefs = computed(() => [
    {
        headerName: "Waktu",
        field: "recorded_at",
        sortable: true,
        resizable: true,
        suppressMovable: true,
        headerClass: "camera-header-center !text-xs sm:!text-sm",
        cellClass: "camera-cell-center !text-[11px] sm:!text-sm !px-1",
        cellStyle: { textAlign: "center" },
        valueFormatter: (params) => formatCameraDateTime(params.value),
        flex: 1.2,
        minWidth: 80,
    },
    {
        headerName: "Akurasi",
        field: "confidence",
        sortable: true,
        resizable: true,
        suppressMovable: true,
        headerClass: "camera-header-center !text-xs sm:!text-sm",
        flex: 1,
        minWidth: 60,
        cellClass: "camera-cell-center !text-[11px] sm:!text-sm !px-1",
        cellStyle: { textAlign: "center" },
        cellRenderer: (params) => {
            return params.value !== null && params.value !== undefined
                ? `${params.value}%`
                : "-";
        },
    },
    {
        headerName: "Status",
        field: "status",
        sortable: true,
        resizable: true,
        suppressMovable: true,
        headerClass: "camera-header-center !text-xs sm:!text-sm",
        flex: 1,
        minWidth: 70,
        cellClass: "camera-cell-center !px-1",
        cellStyle: { textAlign: "center" },
        cellRenderer: (params) => {
            const status = params.value || "Unknown";
            const statusLabelMap = {
                Berkabut: "Kabut",
                "Tidak Berkabut": "Cerah",
                Unknown: "-",
            };

            const statusClasses = {
                Berkabut: "bg-sky-100 text-sky-600",
                "Tidak Berkabut": "bg-green-100 text-green-600",
                Unknown: "bg-gray-100 text-gray-600",
            };

            const badgeClass =
                statusClasses[status] || "bg-gray-100 text-gray-600";

            const wrapper = document.createElement("div");
            wrapper.style.display = "flex";
            wrapper.style.justifyContent = "center";
            wrapper.style.alignItems = "center";
            wrapper.style.width = "100%";
            wrapper.style.height = "100%";

            const div = document.createElement("div");
            div.className = `px-2 py-[3px] rounded text-[10px] sm:text-[11px] font-medium leading-none tracking-wide ${badgeClass}`;
            div.textContent = statusLabelMap[status] || status;
            div.style.display = "inline-block";

            wrapper.appendChild(div);
            return wrapper;
        },
    },
]);

// change pointer on hover
const rowClassRules = ref({
    "cursor-pointer hover:bg-gray-100": (params) => true,
});

// data
const rowDataMap = ref({});
const rowImageMap = ref({});
const paginationMetaMap = ref({});
const rowImageLoadingTimers = ref({});
const isComponentAlive = ref(true);
const autoRefreshInterval = ref(null);
const loadedGreenhouseMap = ref({});
const cameraRequestTokens = new Map();
const cameraFetchControllers = new Map();

const DEFAULT_CAMERA_PER_PAGE = 5;
const CAMERA_PER_PAGE_OPTIONS = [5, 10, 20, 50, 100];
const cameraPageMap = ref({});
const sharedPerPage = ref(DEFAULT_CAMERA_PER_PAGE);

const getGreenhouseLabel = (greenhouse) => {
    const label = String(greenhouse?.name || "").trim();
    const ghId = Number(greenhouse?.id);
    const fallbackNumber = Number.isFinite(ghId) && ghId > 0 ? ghId : null;

    const normalized = label
        .toLowerCase()
        .replace(/[_-]+/g, " ")
        .replace(/\s+/g, " ")
        .trim();

    const labelNumberMatch = normalized.match(/(\d+)$/);
    const labelNumber = labelNumberMatch ? Number(labelNumberMatch[1]) : null;
    const tabNumber = labelNumber ?? fallbackNumber;

    return Number.isFinite(tabNumber) && tabNumber > 0
        ? `GH Von Florist ${tabNumber}`
        : "GH Von Florist";
};

const getCameraLabel = (index) => {
    return `${t("monitoring.camera")} ${index + 1}`;
};

const abortCameraFetch = (gh_id) => {
    const controller = cameraFetchControllers.get(gh_id);
    if (!controller) {
        return;
    }

    controller.abort();
    cameraFetchControllers.delete(gh_id);
};

const abortAllCameraFetches = () => {
    cameraFetchControllers.forEach((controller) => controller.abort());
    cameraFetchControllers.clear();
};

const ensurePaginationMeta = (gh_id) => {
    if (!paginationMetaMap.value[gh_id]) {
        paginationMetaMap.value[gh_id] = {
            total: 0,
            lastPage: 1,
        };
    }

    return paginationMetaMap.value[gh_id];
};

const ensureCameraPage = (gh_id) => {
    if (!cameraPageMap.value[gh_id]) {
        cameraPageMap.value[gh_id] = 1;
    }

    return cameraPageMap.value[gh_id];
};

const getCurrentPage = (gh_id) => {
    return ensureCameraPage(gh_id);
};

const getCameraLastPage = (gh_id) => {
    const meta = ensurePaginationMeta(gh_id);
    return Math.max(1, Number(meta.lastPage || 1));
};

const globalLastPage = computed(() => {
    const greenhouseList = Array.isArray(greenhouses) ? greenhouses : [];
    if (greenhouseList.length === 0) {
        return 1;
    }

    const maxLastPage = greenhouseList.reduce((maxValue, greenhouse) => {
        const meta = ensurePaginationMeta(greenhouse.id);
        return Math.max(maxValue, Number(meta.lastPage || 1));
    }, 1);

    return Math.max(1, maxLastPage);
});

const getPaginationText = (gh_id) => {
    const meta = ensurePaginationMeta(gh_id);
    if (meta.total <= 0) {
        return t("camera.page_no_data");
    }

    return `${getCurrentPage(gh_id)} ${t("common.of")} ${globalLastPage.value}`;
};

const canPrevPage = (gh_id) => {
    return getCurrentPage(gh_id) > 1;
};

const canNextPage = (gh_id) => {
    return getCurrentPage(gh_id) < getCameraLastPage(gh_id);
};

const fetchAllGreenhouses = async ({ force = false } = {}) => {
    const greenhouseList = Array.isArray(greenhouses) ? greenhouses : [];
    const jobs = greenhouseList.map((greenhouse) =>
        fetchData(greenhouse.id, { force })
    );
    await Promise.all(jobs);
};

const goToPage = (gh_id, nextPage) => {
    const parsedGhId = Number(gh_id);
    if (!Number.isFinite(parsedGhId) || parsedGhId <= 0) {
        return;
    }

    const clampedPage = Math.max(
        1,
        Math.min(nextPage, getCameraLastPage(parsedGhId))
    );
    if (clampedPage === getCurrentPage(parsedGhId)) {
        return;
    }

    cameraPageMap.value[parsedGhId] = clampedPage;
    fetchData(parsedGhId, { force: true });
};

const onBtNext = (gh_id) => {
    goToPage(gh_id, getCurrentPage(gh_id) + 1);
};

const onBtPrevious = (gh_id) => {
    goToPage(gh_id, getCurrentPage(gh_id) - 1);
};

const onPerPageChange = (value) => {
    const parsedPerPage = Number(value);
    sharedPerPage.value =
        Number.isFinite(parsedPerPage) && parsedPerPage > 0
            ? parsedPerPage
            : DEFAULT_CAMERA_PER_PAGE;

    const greenhouseList = Array.isArray(greenhouses) ? greenhouses : [];
    greenhouseList.forEach((greenhouse) => {
        cameraPageMap.value[greenhouse.id] = 1;
    });
    fetchAllGreenhouses({ force: true });
};

// fetch data table
const fetchData = async (gh_id, { force = false } = {}) => {
    const parsedGhId = Number(gh_id);
    if (!Number.isFinite(parsedGhId) || parsedGhId <= 0) {
        return;
    }

    if (
        !force &&
        loadedGreenhouseMap.value[parsedGhId] &&
        Array.isArray(rowDataMap.value[parsedGhId])
    ) {
        return;
    }

    ensurePaginationMeta(parsedGhId);
    const requestToken = (cameraRequestTokens.get(parsedGhId) || 0) + 1;
    cameraRequestTokens.set(parsedGhId, requestToken);
    abortCameraFetch(parsedGhId);

    let controller = null;

    try {
        rowTableLoading.value[parsedGhId] = true;

        const queryData = {
            gh_id: parsedGhId,
            page: getCurrentPage(parsedGhId),
            per_page: sharedPerPage.value,
        };
        const url =
            `/api/camera-per-gh?dict=` +
            encodeURIComponent(JSON.stringify(queryData));

        controller = new AbortController();
        cameraFetchControllers.set(parsedGhId, controller);

        const response = await fetch(url, {
            method: "GET",
            headers: { "Content-Type": "application/json" },
            signal: controller.signal,
        });

        const jsonData = await response.json();

        if (
            !isComponentAlive.value ||
            cameraRequestTokens.get(parsedGhId) !== requestToken
        ) {
            return;
        }

        if (Array.isArray(jsonData.data)) {
            const nextPreview = jsonData.data[0] || null;
            if (rowImageMap.value[parsedGhId]?.image !== nextPreview?.image) {
                rowImageLoading.value[parsedGhId] = Boolean(nextPreview);
            }

            rowDataMap.value[parsedGhId] = jsonData.data;
            rowImageMap.value[parsedGhId] = nextPreview;
            
            // Preload images background (optimalisasi performa klik baris)
            jsonData.data.forEach((item) => {
                if (item && item.image) {
                    const img = new Image();
                    img.src = item.image;
                }
            });
            const meta = ensurePaginationMeta(parsedGhId);
            meta.total = Number(jsonData.total || 0);
            meta.lastPage = Number(jsonData.last_page || 1);
            loadedGreenhouseMap.value[parsedGhId] = true;

            const maxPage = getCameraLastPage(parsedGhId);
            if (getCurrentPage(parsedGhId) > maxPage) {
                cameraPageMap.value[parsedGhId] = maxPage;
                await fetchData(parsedGhId, { force: true });
                return;
            }
        } else {
            toast.error(t("camera.failed_load_data"));
            console.error("Data format error: Expected array", jsonData);
        }
    } catch (error) {
        if (!isComponentAlive.value || error?.name === "AbortError") {
            return;
        }

        toast.error(t("camera.failed_load_data"));
        console.error("Fetch error:", error);
        rowImageLoading.value[parsedGhId] = false;
    } finally {
        if (
            controller &&
            cameraFetchControllers.get(parsedGhId) === controller
        ) {
            cameraFetchControllers.delete(parsedGhId);
        }

        if (cameraRequestTokens.get(parsedGhId) === requestToken) {
            rowTableLoading.value[parsedGhId] = false;
        }
    }
};

const loadGreenhouseDataIfNeeded = (gh_id, { force = false } = {}) => {
    const parsedGhId = Number(gh_id);
    if (!Number.isFinite(parsedGhId) || parsedGhId <= 0) {
        return;
    }

    ensurePaginationState(parsedGhId);
    fetchData(parsedGhId, { force });
};

const AUTO_REFRESH_INTERVAL_MS = 30_000; // 30 detik
onMounted(() => {
    fetchAllGreenhouses();

    autoRefreshInterval.value = setInterval(() => {
        if (isComponentAlive.value) {
            fetchAllGreenhouses({ force: true });
        }
    }, AUTO_REFRESH_INTERVAL_MS);
});

const formatDate = (date) => {
    return new Date(date).toISOString().split("T")[0];
};

const exportData = async () => {
    isExporting.value = true;

    // 1. Cek tanggal
    if (!daterange.value) {
        toast.warning(t("camera.date_range_required"));
        isExporting.value = false;
        return;
    }

    // 2. Siapkan Payload
    const payload = {
        start_date: formatDate(daterange.value[0]),
        end_date: formatDate(daterange.value[1]),
        gh_id: selectedGreenhouse.value,
    };

    try {
        // 3. Tembak Backend
        const response = await axios.post("/api/export-camera", payload, {
            responseType: "blob", // PENTING: Response berupa file binary
        });

        // 4. Proses Download
        const blob = new Blob([response.data], {
            type: response.headers["content-type"], // Ambil tipe file dari server
        });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement("a");
        a.href = url;
        const filePrefix =
            locale.value === "id" ? "laporan_kamera" : "camera_report";
        a.download = `${filePrefix}_${payload.start_date}_to_${payload.end_date}.zip`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);

        toast.success(t("camera.zip_downloaded"));

        isExporting.value = false;
    } catch (error) {
        console.error(error);
        if (error.response && error.response.status === 404) {
            toast.error(t("camera.no_data_selected_range"));
        } else {
            toast.error(t("camera.failed_download"));
        }
        isExporting.value = false;
    }
};

const onRowSelected = (event, gh_id) => {
    if (!isComponentAlive.value) {
        return;
    }

    if (event.node.isSelected()) {
        rowImageLoading.value[gh_id] = true;

        if (rowImageMap.value[gh_id]?.image !== event.data.image) {
            rowImageMap.value[gh_id] = { ...event.data };
        }

        if (rowImageLoadingTimers.value[gh_id]) {
            clearTimeout(rowImageLoadingTimers.value[gh_id]);
        }
        // Timer dihapus karena sekarang sudah menggunakan event @load yang benar
    }
};

onUnmounted(() => {
    isComponentAlive.value = false;

    if (autoRefreshInterval.value) {
        clearInterval(autoRefreshInterval.value);
        autoRefreshInterval.value = null;
    }

    abortAllCameraFetches();
    Object.values(rowImageLoadingTimers.value).forEach((timerId) => {
        clearTimeout(timerId);
    });
    rowImageLoadingTimers.value = {};
});
</script>

<template>
    <Head :title="t('title.camera')" />

    <BreezeAuthenticatedLayout :titlePage="'Camera'">
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ t("title.camera") }}
            </h2>
        </template>

        <div class="py-2 font-sans">
            <div class="max-w-7xl mx-auto sm:px-2 lg:px-2">
                <div class="flex flex-col lg:flex-row gap-2 mb-2">
                    <div
                        class="bg-white overflow-hidden shadow-sm rounded-lg p-4 w-full lg:w-3/5 flex items-center"
                    >
                        <div class="flex flex-col w-full gap-2">
                            <div class="flex justify-between">
                                <p>{{ t("camera.time") }}</p>
                                <DigitalClock />
                            </div>
                            <div class="flex justify-between">
                                <p>{{ t("camera.latest_data") }}</p>
                                <p>
                                    {{ latestData || "-" }}
                                </p>
                            </div>
                        </div>
                    </div>
                    <div
                        class="bg-white overflow-hidden shadow-sm rounded-lg p-4 w-full lg:w-2/5"
                    >
                        <div class="flex flex-col w-full">
                            <div class="flex justify-between">
                                <p class="text-lg">{{ t("camera.export") }}</p>
                            </div>
                            <div class="flex justify-between gap-2">
                                <select
                                    v-model="selectedGreenhouse"
                                    class="w-3/4 sm:w-1/2 p-1.5 border rounded-lg text-center focus:ring focus:ring-blue-300 hover:border-blue-400 transition"
                                >
                                    <option value="">{{ t("camera.all") }}</option>
                                    <option
                                        v-for="greenhouse in greenhouses"
                                        :key="greenhouse.id"
                                        :value="greenhouse.id"
                                    >
                                        {{ greenhouse.name }}
                                    </option>
                                </select>
                                <VueDatePicker
                                    v-model="daterange"
                                    range
                                    :teleport="true"
                                    position="left"
                                    :placeholder="t('camera.pick_date_range')"
                                />
                                <button
                                    :disabled="isExporting"
                                    @click="exportData"
                                    class="bg-green-500 text-white p-1 rounded"
                                >
                                    <i
                                        :class="[
                                            isExporting
                                                ? 'fas fa-spinner fa-spin'
                                                : 'far fa-file-excel',
                                            ' w-8 h-full py-1.5',
                                        ]"
                                    ></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div
                    class="mb-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2"
                >
                    <div
                        v-for="(actuator, index) in actuators"
                        :key="index"
                        class="bg-white overflow-hidden shadow-sm rounded-lg p-4"
                    >
                        <div
                            class="flex items-center justify-between h-full"
                        >
                            <div class="flex items-center">
                                <i
                                    :class="[
                                        actuator.icon,
                                        actuator.color,
                                        getActuatorIconAnimationClass(
                                            actuator
                                        ),
                                        'text-3xl w-10',
                                    ]"
                                ></i>
                                <div class="ml-4">
                                    <p
                                        class="font-semibold text-gray-800"
                                    >
                                        {{ actuator.name }}
                                    </p>
                                    <p class="text-sm text-gray-500">
                                        {{ t("camera.status") }}
                                    </p>
                                </div>
                            </div>

                            <div class="flex flex-col items-end gap-1">
                                <div
                                    :class="actuator.status ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'"
                                    class="px-3 py-1 text-xs font-bold rounded-full"
                                >
                                    {{
                                        actuator.status
                                            ? t("monitoring.on")
                                            : t("monitoring.off")
                                    }}
                                </div>
                                <p
                                    :class="actuator.gatewayOnline ? 'text-green-600' : 'text-red-600'"
                                    class="text-[11px] font-semibold uppercase tracking-wide"
                                >
                                    {{
                                        actuator.gatewayOnline
                                            ? t("monitoring.online")
                                            : t("monitoring.offline")
                                    }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div
                    class="mb-2 flex flex-col items-center gap-2 rounded-lg border border-gray-200 bg-white p-3 sm:flex-row sm:items-center sm:justify-center"
                >
                    <div
                        class="flex w-full items-center justify-center gap-2 sm:w-auto"
                    >
                        <span class="text-xs font-medium text-gray-500">{{
                            t("camera.show")
                        }}</span>
                        <select
                            :value="sharedPerPage"
                            class="h-8 rounded border border-gray-300 px-2 text-sm"
                            @change="onPerPageChange($event.target.value)"
                        >
                            <option
                                v-for="option in CAMERA_PER_PAGE_OPTIONS"
                                :key="`shared-per-page-${option}`"
                                :value="option"
                            >
                                {{ option }}
                            </option>
                        </select>
                        <span class="text-xs font-medium text-gray-500">{{
                            t("camera.per_page")
                        }}</span>
                    </div>
                </div>

                <div class="flex flex-col lg:flex-row gap-2">
                    <div
                        v-for="(greenhouse, index) in greenhouses"
                        :key="greenhouse.id"
                        class="bg-white overflow-hidden shadow-sm rounded-lg p-4 w-full"
                    >
                        <div
                            class="mb-4 flex w-full flex-col items-center gap-2 text-center md:flex-row md:items-center md:justify-between md:text-left"
                        >
                            <div class="flex flex-col">
                                <p class="text-lg font-semibold leading-tight">
                                    {{ getCameraLabel(index) }}
                                </p>
                                <p
                                    class="text-xs font-medium uppercase tracking-wide text-gray-500"
                                >
                                    {{ getGreenhouseLabel(greenhouse) }}
                                </p>
                            </div>

                            
                        </div>

                        <div class="flex flex-col gap-4 w-full">
                            <div
                                class="p-3 border rounded-lg flex justify-center items-center w-full h-[300px] relative"
                            >
                                <div
                                    v-if="rowImageLoading[greenhouse.id]"
                                    class="absolute inset-0 flex justify-center items-center bg-white z-10"
                                >
                                    <i
                                        class="fas fa-spinner fa-spin text-gray-400 text-3xl"
                                    ></i>
                                </div>
                                <img
                                    :src="
                                        rowImageMap[greenhouse.id]
                                            ?.image || '/images/no-image.svg'
                                    "
                                    :alt="t('title.camera')"
                                    class="w-full h-full object-contain"
                                    @load="
                                        rowImageLoading[greenhouse.id] = false
                                    "
                                    @error="
                                        (e) => {
                                            e.target.src =
                                                '/images/no-image.svg';
                                            rowImageLoading[
                                                greenhouse.id
                                            ] = false;
                                        }
                                    "
                                />
                            </div>
                            <div class="flex flex-col gap-2">
                                <div class="relative">
                                    <div
                                        v-if="rowTableLoading[greenhouse.id]"
                                        class="absolute inset-0 z-10 flex items-center justify-center bg-white/80"
                                    >
                                        <div
                                            class="flex flex-col items-center gap-3"
                                        >
                                            <div
                                                class="h-10 w-10 animate-spin rounded-full border-b-2 border-indigo-600"
                                            ></div>
                                            <span
                                                class="font-medium text-gray-600"
                                                >{{
                                                    t("table.loading_data")
                                                }}</span
                                            >
                                        </div>
                                    </div>
                                    <ag-grid-vue
                                        class="camera-grid w-full"
                                        :rowData="
                                            rowDataMap[greenhouse.id] || []
                                        "
                                        :columnDefs="columnDefs"
                                        :domLayout="'autoHeight'"
                                        :animateRows="true"
                                        :suppressPaginationPanel="true"
                                        :rowSelection="rowSelectionConfig"
                                        :rowClassRules="rowClassRules"
                                        @row-selected="
                                            (event) =>
                                                onRowSelected(
                                                    event,
                                                    greenhouse.id
                                                )
                                        "
                                        :theme="themeAlpine"
                                    >
                                    </ag-grid-vue>
                                </div>
                                <div
                                    class="flex items-center justify-center gap-1 rounded-lg border border-gray-200 bg-gray-50 p-2 text-center sm:gap-2"
                                >
                                    <Button
                                        @click="onBtPrevious(greenhouse.id)"
                                        :disabled="!canPrevPage(greenhouse.id)"
                                    >
                                        <i class="fas fa-angle-left"></i>
                                    </Button>
                                    <span
                                        class="min-w-[92px] text-center text-xs font-medium text-gray-700 sm:text-sm"
                                    >
                                        {{ getPaginationText(greenhouse.id) }}
                                    </span>
                                    <Button
                                        @click="onBtNext(greenhouse.id)"
                                        :disabled="!canNextPage(greenhouse.id)"
                                    >
                                        <i class="fas fa-angle-right"></i>
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </BreezeAuthenticatedLayout>
</template>

<style scoped>
:deep(.camera-grid .camera-header-center .ag-header-cell-label) {
    justify-content: center;
}

:deep(.camera-grid .ag-header-cell::after),
:deep(.camera-grid .ag-header-cell-resize::after) {
    display: none !important;
}

:deep(.camera-grid .ag-header-cell) {
    border-right: none !important;
}

:deep(.camera-grid .camera-cell-center) {
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
}

.actuator-fan-active {
    animation: actuator-fan-spin 1.5s linear infinite;
    transform-origin: 50% 50%;
}

.actuator-dehumidifier-active {
    animation: actuator-dehumidifier-pulse 1.7s ease-in-out infinite;
    transform-origin: 50% 50%;
}

@keyframes actuator-fan-spin {
    from {
        transform: rotate(0deg);
    }
    to {
        transform: rotate(360deg);
    }
}

@keyframes actuator-dehumidifier-pulse {
    0%,
    100% {
        transform: translateY(0) scale(1);
        opacity: 1;
    }
    50% {
        transform: translateY(-2px) scale(1.08);
        opacity: 0.82;
    }
}
</style>
