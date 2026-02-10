// Cascading Address Dropdowns for Philippine Locations
// This handles the cascading dropdowns: Country -> Province -> City/Municipality -> Barangay

document.addEventListener('DOMContentLoaded', function() {
    const countrySelect = document.getElementById('country');
    const provinceSelect = document.getElementById('cityProvince');
    const citySelect = document.getElementById('municipality');
    const barangaySelect = document.getElementById('baranggay');
    
    const provinceText = document.getElementById('cityProvinceText');
    const cityText = document.getElementById('municipalityText');
    const barangayText = document.getElementById('baranggayText');
    
    if (!countrySelect || !provinceSelect || !citySelect || !barangaySelect) {
        return; // Exit if elements don't exist
    }
    
    // Initialize: Check if country is already selected (from form submission)
    if (countrySelect.value === 'Philippines') {
        enableProvinceDropdown();
        loadProvinces();
        
        // If province is already selected, load cities
        if (provinceSelect.value) {
            enableCityDropdown();
            loadCities(provinceSelect.value);
            
            // If city is already selected, load barangays
            if (citySelect.value) {
                enableBarangayDropdown();
                loadBarangays(provinceSelect.value, citySelect.value);
            }
        }
    } else if (countrySelect.value === 'Other') {
        enableTextInputs();
    }
    
    // Country change handler
    countrySelect.addEventListener('change', function() {
        const selectedCountry = this.value;
        
        // Reset all dependent dropdowns
        resetDropdown(provinceSelect);
        resetDropdown(citySelect);
        resetDropdown(barangaySelect);
        
        if (selectedCountry === 'Philippines') {
            // Show dropdown selects, hide text inputs
            enableProvinceDropdown();
            loadProvinces();
        } else if (selectedCountry === 'Other') {
            // Show text inputs, hide dropdown selects
            enableTextInputs();
        } else {
            // No country selected - disable everything
            disableAllDropdowns();
        }
    });
    
    // Province change handler
    provinceSelect.addEventListener('change', function() {
        const selectedProvince = this.value;
        
        // Reset city and barangay dropdowns
        resetDropdown(citySelect);
        resetDropdown(barangaySelect);
        
        if (selectedProvince) {
            enableCityDropdown();
            loadCities(selectedProvince);
        } else {
            disableCityDropdown();
            disableBarangayDropdown();
        }
    });
    
    // City change handler
    citySelect.addEventListener('change', function() {
        const selectedCity = this.value;
        const selectedProvince = provinceSelect.value;
        
        // Reset barangay dropdown
        resetDropdown(barangaySelect);
        
        if (selectedCity && selectedProvince) {
            enableBarangayDropdown();
            loadBarangays(selectedProvince, selectedCity);
        } else {
            disableBarangayDropdown();
        }
    });
    
    // Helper function to reset a dropdown
    function resetDropdown(selectElement) {
        selectElement.innerHTML = '<option value="">' + selectElement.querySelector('option').textContent + '</option>';
        selectElement.value = '';
    }
    
    // Load provinces into dropdown
    function loadProvinces() {
        const provinces = getProvinces();
        provinceSelect.innerHTML = '<option value="">Select Province</option>';
        
        provinces.forEach(province => {
            const option = document.createElement('option');
            option.value = province;
            option.textContent = province;
            provinceSelect.appendChild(option);
        });
    }
    
    // Load cities for selected province
    function loadCities(province) {
        const cities = getCities(province);
        citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
        
        cities.forEach(city => {
            const option = document.createElement('option');
            option.value = city;
            option.textContent = city;
            citySelect.appendChild(option);
        });
    }
    
    // Load barangays for selected city
    function loadBarangays(province, city) {
        const barangays = getBarangays(province, city);
        barangaySelect.innerHTML = '<option value="">Select Barangay</option>';
        
        barangays.forEach(barangay => {
            const option = document.createElement('option');
            option.value = barangay;
            option.textContent = barangay;
            barangaySelect.appendChild(option);
        });
    }
    
    // Enable/disable functions
    function enableProvinceDropdown() {
        provinceSelect.disabled = false;
        provinceSelect.style.display = 'block';
        provinceText.style.display = 'none';
        provinceText.removeAttribute('required');
        provinceSelect.setAttribute('required', 'required');
    }
    
    function enableCityDropdown() {
        citySelect.disabled = false;
        citySelect.style.display = 'block';
        cityText.style.display = 'none';
        cityText.removeAttribute('required');
        citySelect.setAttribute('required', 'required');
    }
    
    function enableBarangayDropdown() {
        barangaySelect.disabled = false;
        barangaySelect.style.display = 'block';
        barangayText.style.display = 'none';
        barangayText.removeAttribute('required');
        barangaySelect.setAttribute('required', 'required');
    }
    
    function disableCityDropdown() {
        citySelect.disabled = true;
        citySelect.value = '';
    }
    
    function disableBarangayDropdown() {
        barangaySelect.disabled = true;
        barangaySelect.value = '';
    }
    
    function disableAllDropdowns() {
        provinceSelect.disabled = true;
        citySelect.disabled = true;
        barangaySelect.disabled = true;
    }
    
    function enableTextInputs() {
        // Hide dropdowns
        provinceSelect.style.display = 'none';
        citySelect.style.display = 'none';
        barangaySelect.style.display = 'none';
        
        // Show text inputs
        provinceText.style.display = 'block';
        cityText.style.display = 'block';
        barangayText.style.display = 'block';
        
        // Set required attributes
        provinceText.setAttribute('required', 'required');
        cityText.setAttribute('required', 'required');
        barangayText.setAttribute('required', 'required');
        
        // Remove required from hidden selects
        provinceSelect.removeAttribute('required');
        citySelect.removeAttribute('required');
        barangaySelect.removeAttribute('required');
    }
    
    // Before form submission, handle form data based on country selection
    const form = document.getElementById('formBox');
    if (form) {
        form.addEventListener('submit', function(e) {
            if (countrySelect.value === 'Other') {
                // For "Other" country, disable selects so text inputs are submitted
                provinceSelect.disabled = true;
                provinceSelect.removeAttribute('name');
                citySelect.disabled = true;
                citySelect.removeAttribute('name');
                barangaySelect.disabled = true;
                barangaySelect.removeAttribute('name');
                
                // Ensure text inputs have the correct names
                provinceText.setAttribute('name', 'cityProvince');
                cityText.setAttribute('name', 'municipality');
                barangayText.setAttribute('name', 'baranggay');
            } else if (countrySelect.value === 'Philippines') {
                // For Philippines, disable text inputs so selects are submitted
                provinceText.disabled = true;
                provinceText.removeAttribute('name');
                cityText.disabled = true;
                cityText.removeAttribute('name');
                barangayText.disabled = true;
                barangayText.removeAttribute('name');
            }
        });
    }
});

