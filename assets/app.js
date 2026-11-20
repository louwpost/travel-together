const rows = Array.from(document.querySelectorAll(".country-row"));
const searchInput = document.querySelector("#country-search");
const clearSearchButton = document.querySelector(".search-clear");
const filterButtons = Array.from(document.querySelectorAll("[data-filter]"));
const mapFilterButtons = Array.from(document.querySelectorAll("[data-map-filter]"));
const emptyState = document.querySelector("#empty-state");
const saveStatus = document.querySelector("#save-status");
const mapCountries = document.querySelector("#world-map-countries");
const countryRows = new Map(rows.map((row) => [row.dataset.countryCode, row]));
const personNames = Array.from(document.querySelectorAll(".country-table thead .person-column"))
    .map((heading) => heading.textContent.trim());
let activeFilter = "all";
let activeMapFilter = "all";
let activeMapCountryCode = null;

const svgNamespace = "http://www.w3.org/2000/svg";
const mapWidth = 1000;
const mapHeight = 500;

function projectRing(ring, anchorLongitude) {
    let previousLongitude = null;
    const points = [];
    let longitudeTotal = 0;

    for (const [rawLongitude, latitude] of ring) {
        let longitude = rawLongitude;
        if (previousLongitude === null) {
            longitude += 360 * Math.round((anchorLongitude - longitude) / 360);
        } else {
            while (longitude - previousLongitude > 180) longitude -= 360;
            while (longitude - previousLongitude < -180) longitude += 360;
        }

        previousLongitude = longitude;
        longitudeTotal += longitude;
        points.push([
            ((longitude + 180) / 360) * mapWidth,
            ((90 - latitude) / 180) * mapHeight,
        ]);
    }

    return {
        points,
        centerLongitude: longitudeTotal / ring.length,
    };
}

function polygonPath(rings) {
    const outerRing = projectRing(rings[0], rings[0][0][0]);
    const paths = [outerRing.points];

    for (const ring of rings.slice(1)) {
        paths.push(projectRing(ring, outerRing.centerLongitude).points);
    }

    return paths.map((points) => points.map(([x, y], index) =>
        `${index === 0 ? "M" : "L"}${x.toFixed(2)},${y.toFixed(2)}`).join(" ") + " Z").join(" ");
}

function createMap() {
    const features = JSON.parse(document.querySelector("#world-map-data").textContent);

    for (const feature of features) {
        const row = countryRows.get(feature.code);
        if (!row) continue;

        const polygons = feature.type === "Polygon" ? [feature.coordinates] : feature.coordinates;
        const pathData = polygons.map(polygonPath).join(" ");
        const group = document.createElementNS(svgNamespace, "g");
        group.classList.add("map-country");
        group.dataset.countryCode = feature.code;
        group.setAttribute("role", "button");
        group.setAttribute("tabindex", "0");

        const title = document.createElementNS(svgNamespace, "title");
        group.append(title);

        for (const offset of [0, -mapWidth, mapWidth]) {
            const path = document.createElementNS(svgNamespace, "path");
            path.setAttribute("d", pathData);
            path.setAttribute("fill-rule", "evenodd");
            if (offset !== 0) {
                path.setAttribute("transform", `translate(${offset} 0)`);
                path.setAttribute("aria-hidden", "true");
            }
            group.append(path);
        }

        mapCountries.append(group);
    }
}

function updateMap() {
    for (const group of mapCountries.children) {
        const row = countryRows.get(group.dataset.countryCode);
        const checkboxes = row.querySelectorAll("[data-visit]");
        const firstVisited = checkboxes[0].checked;
        const secondVisited = checkboxes[1].checked;
        let state = "none";

        if (activeMapFilter === "both") {
            if (firstVisited && secondVisited) state = "shared";
        } else if (activeMapFilter === "person_1") {
            if (firstVisited) state = "person-1";
        } else if (activeMapFilter === "person_2") {
            if (secondVisited) state = "person-2";
        } else if (firstVisited && secondVisited) {
            state = "shared";
        } else if (firstVisited) {
            state = "person-1";
        } else if (secondVisited) {
            state = "person-2";
        }

        const visitDescription = firstVisited && secondVisited
            ? `visited by ${personNames[0]} and ${personNames[1]}`
            : firstVisited
                ? `visited by ${personNames[0]}`
                : secondVisited
                    ? `visited by ${personNames[1]}`
                    : "not visited yet";
        const countryName = row.dataset.countryName;
        group.dataset.state = state;
        group.setAttribute("aria-pressed", String(group.dataset.countryCode === activeMapCountryCode));
        group.setAttribute("aria-label", `${countryName}, ${visitDescription}. Select to find in checklist; select again to clear search.`);
        group.querySelector("title").textContent = `${countryName} · ${visitDescription}`;
    }
}

function focusCountryInChecklist(group) {
    const row = countryRows.get(group.dataset.countryCode);
    if (!row) return;

    if (activeMapCountryCode === group.dataset.countryCode) {
        clearCountrySearch();
        return;
    }

    activeMapCountryCode = group.dataset.countryCode;
    searchInput.value = row.dataset.countryName;
    activeFilter = "all";
    filterButtons.forEach((button) => {
        button.setAttribute("aria-pressed", String(button.dataset.filter === "all"));
    });
    updateMap();
    filterCountries();
    row.scrollIntoView({
        behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth",
        block: "center",
    });
    row.querySelector("[data-visit]").focus();
}

function clearCountrySearch() {
    searchInput.value = "";
    activeMapCountryCode = null;
    updateMap();
    filterCountries();
}

createMap();
updateMap();

mapFilterButtons.forEach((button) => {
    button.addEventListener("click", () => {
        activeMapFilter = button.dataset.mapFilter;
        mapFilterButtons.forEach((filterButton) => {
            filterButton.setAttribute("aria-pressed", String(filterButton === button));
        });
        updateMap();
    });
});

mapCountries.addEventListener("click", (event) => {
    const group = event.target.closest(".map-country");
    if (group) focusCountryInChecklist(group);
});

mapCountries.addEventListener("keydown", (event) => {
    const group = event.target.closest(".map-country");
    if (group && (event.key === "Enter" || event.key === " ")) {
        event.preventDefault();
        focusCountryInChecklist(group);
    }
});

function filterCountries() {
    const query = searchInput.value.trim().toLocaleLowerCase();
    let visibleRows = 0;
    clearSearchButton.hidden = query.length === 0;

    rows.forEach((row) => {
        const matchesSearch = row.dataset.countryName.toLocaleLowerCase().includes(query)
            || row.querySelector(".country-code").textContent.toLocaleLowerCase().includes(query);
        const checks = Array.from(row.querySelectorAll("[data-visit]"));
        const visitedCount = checks.filter((checkbox) => checkbox.checked).length;
        const matchesFilter = activeFilter === "all"
            || (activeFilter === "both" && visitedCount === 2)
            || (activeFilter === "one" && visitedCount === 1)
            || (activeFilter === "none" && visitedCount === 0);

        row.hidden = !matchesSearch || !matchesFilter;
        if (!row.hidden) visibleRows += 1;
    });

    emptyState.hidden = visibleRows !== 0;
}

searchInput.addEventListener("input", () => {
    activeMapCountryCode = null;
    updateMap();
    filterCountries();
});
clearSearchButton.addEventListener("click", () => {
    clearCountrySearch();
    searchInput.focus();
});
filterButtons.forEach((button) => {
    button.addEventListener("click", () => {
        activeFilter = button.dataset.filter;
        filterButtons.forEach((filterButton) => {
            filterButton.setAttribute("aria-pressed", String(filterButton === button));
        });
        filterCountries();
    });
});

document.addEventListener("keydown", (event) => {
    if (event.key === "/" && !["INPUT", "SELECT", "TEXTAREA"].includes(document.activeElement.tagName)) {
        event.preventDefault();
        searchInput.focus();
    }
    if (event.key === "Escape" && document.activeElement === searchInput) {
        clearCountrySearch();
        searchInput.blur();
    }
});

document.addEventListener("change", async (event) => {
    const checkbox = event.target.closest("[data-visit]");
    if (!checkbox) return;

    const form = checkbox.closest("form");
    const previousValue = !checkbox.checked;
    const formData = new FormData(form);
    formData.set("visited", checkbox.checked ? "1" : "0");
    checkbox.disabled = true;
    saveStatus.textContent = "Saving…";

    try {
        const response = await fetch(window.location.href, {
            method: "POST",
            headers: { "X-Requested-With": "XMLHttpRequest" },
            body: formData,
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || "Could not save this visit.");

        for (const [personId, count] of Object.entries(result.counts)) {
            document.querySelector(`[data-count="${personId}"]`).textContent = count;
        }
        document.querySelector("[data-shared-count]").textContent = result.shared;
        updateMap();
        filterCountries();
        saveStatus.textContent = "Saved.";
    } catch (error) {
        checkbox.checked = previousValue;
        saveStatus.textContent = error.message;
    } finally {
        checkbox.disabled = false;
    }
});
