document.addEventListener('DOMContentLoaded', () => {
	const storedData = getStoredRegistrationData();
	const checkbox = document.getElementById('confirmDetailsCheckbox');
	const submitBtn = document.getElementById('submitFinalBtn');

	if (!storedData) {
		handleMissingData(checkbox, submitBtn);
		return;
	}

	populateConfirmationDetails(storedData);
	initializeCheckboxBehavior(checkbox, submitBtn);
	initializeSubmitBehavior(submitBtn, checkbox, storedData);
});

function getStoredRegistrationData() {
	try {
		const saved = sessionStorage.getItem('studentRegistrationData');
		if (!saved) {
			return null;
		}
		return JSON.parse(saved);
	} catch (error) {
		console.error('Unable to parse stored registration data:', error);
		return null;
	}
}

function handleMissingData(checkbox, submitBtn) {
	const container = document.querySelector('.data-privacy-content');
	if (container) {
		const notice = document.createElement('div');
		notice.className = 'validation-message';
		notice.style.cssText = `
			margin: 20px auto;
			padding: 16px 24px;
			border-radius: 8px;
			background: #fff3cd;
			color: #856404;
			border: 1px solid #ffeeba;
			text-align: center;
			max-width: 600px;
		`;
		notice.textContent = 'We could not find any registration details to confirm. Please return to the registration form and fill it out again.';
		container.insertBefore(notice, container.firstChild);
	}

	if (checkbox) {
		checkbox.disabled = true;
	}

	if (submitBtn) {
		submitBtn.disabled = true;
		submitBtn.textContent = 'Return to Registration';
		submitBtn.addEventListener('click', (event) => {
			event.preventDefault();
			window.location.href = 'student-registration-portal.php';
		});
	}
}

function populateConfirmationDetails(data) {
	const formatters = {
		confirmFullName: () => buildFullName(data),
		confirmDOB: () => formatDate(data.dob),
		confirmGender: () => data.gender,
		confirmNationality: () => data.nationality,
		confirmPhone: () => data.phoneNumber,
		confirmProgram: () => data.program,
		confirmEducationalStatus: () => data.educationalStatus,
		confirmCountry: () => data.country,
		confirmCityProvince: () => data.cityProvince,
		confirmMunicipality: () => data.municipality,
		confirmBaranggay: () => data.baranggay,
		confirmAddress: () => buildAddress(data.address, data.addressLine2),
		confirmPostalCode: () => data.postalCode,
		confirmMotherName: () => data.motherName,
		confirmMotherPhone: () => data.motherPhoneNumber,
		confirmMotherOccupation: () => data.motherOccupation,
		confirmFatherName: () => data.fatherName,
		confirmFatherPhone: () => data.fatherPhoneNumber,
		confirmFatherOccupation: () => data.fatherOccupation,
		confirmEmergencyName: () => data.emergencyName,
		confirmEmergencyPhone: () => data.emergencyPhoneNumber,
		confirmEmergencyAddress: () => data.emergencyAddress,
		confirmEmail: () => data.email
	};

	Object.entries(formatters).forEach(([id, getValue]) => {
		setDetailValue(id, getValue());
	});
}

function buildFullName(data) {
	const parts = [];
	if (data.firstName) {
		parts.push(data.firstName);
	}
	if (data.middleName && !data.noMiddleName) {
		parts.push(data.middleName);
	}
	if (data.lastName) {
		parts.push(data.lastName);
	}
	if (data.suffix && data.suffix !== 'N/A') {
		parts.push(data.suffix);
	}
	return parts.join(' ');
}

function formatDate(dateValue) {
	if (!dateValue) {
		return '';
	}
	const parts = dateValue.split('-');
	if (parts.length !== 3) {
		return dateValue;
	}
	const [year, month, day] = parts;
	return `${month}/${day}/${year}`;
}

function buildAddress(addressLine1, addressLine2) {
	if (!addressLine1 && !addressLine2) {
		return '';
	}
	return [addressLine1, addressLine2].filter(Boolean).join(', ');
}

function setDetailValue(id, value) {
	const element = document.getElementById(id);
	if (!element) {
		return;
	}
	const displayValue = value && value.toString().trim() !== '' ? value : 'N/A';
	element.textContent = displayValue;
}

function initializeCheckboxBehavior(checkbox, submitBtn) {
	if (!checkbox || !submitBtn) {
		return;
	}

	const updateButtonState = () => {
		const enabled = checkbox.checked;
		submitBtn.disabled = !enabled;
		submitBtn.style.opacity = enabled ? '1' : '0.6';
		submitBtn.style.cursor = enabled ? 'pointer' : 'not-allowed';
	};

	updateButtonState();
	checkbox.addEventListener('change', updateButtonState);
}

function initializeSubmitBehavior(submitBtn, checkbox, data) {
	if (!submitBtn) {
		return;
	}

	submitBtn.addEventListener('click', (event) => {
		event.preventDefault();
		if (checkbox && !checkbox.checked) {
			return;
		}
		submitBtn.disabled = true;
		submitBtn.textContent = 'Submitting...';
		submitBtn.style.cursor = 'progress';
		performFinalSubmission(data);
	});
}

function performFinalSubmission(data) {
	const form = document.createElement('form');
	form.method = 'POST';
	form.action = 'student-registration-portal.php';

	const fields = mapDataToFields(data);
	Object.entries(fields).forEach(([name, value]) => {
		if (typeof value === 'undefined' || value === null) {
			return;
		}
		const input = document.createElement('input');
		input.type = 'hidden';
		input.name = name;
		input.value = value;
		form.appendChild(input);
	});

	document.body.appendChild(form);
	sessionStorage.removeItem('studentRegistrationData');
	form.submit();
}

function mapDataToFields(data) {
	const mapped = {
		firstName: data.firstName,
		middleName: data.middleName,
		lastName: data.lastName,
		suffix: data.suffix,
		dob: data.dob,
		gender: data.gender,
		nationality: data.nationality,
		phoneNumber: data.phoneNumber,
		program: data.program,
		educationalStatus: data.educationalStatus,
		country: data.country,
		cityProvince: data.cityProvince,
		municipality: data.municipality,
		baranggay: data.baranggay,
		address: data.address,
		addressLine2: data.addressLine2,
		postalCode: data.postalCode,
		motherName: data.motherName,
		motherPhoneNumber: data.motherPhoneNumber,
		motherOccupation: data.motherOccupation,
		fatherName: data.fatherName,
		fatherPhoneNumber: data.fatherPhoneNumber,
		fatherOccupation: data.fatherOccupation,
		emergencyName: data.emergencyName,
		emergencyPhoneNumber: data.emergencyPhoneNumber,
		emergencyAddress: data.emergencyAddress,
		email: data.email,
		confirmEmail: data.confirmEmail || data.email,
		password: data.password,
		confirmPassword: data.confirmPassword || data.password
	};

	if (data.noMiddleName) {
		mapped.noMiddleName = 'on';
	}

	return mapped;
}
