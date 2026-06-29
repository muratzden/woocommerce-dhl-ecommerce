(function () {
	'use strict';

	var data = window.shippilotLabelData || {};
	var zoom = 100;
	var paper = 'a5';
	var orientation = 'landscape';

	function byId(id) {
		return document.getElementById(id);
	}

	function setPageAccent() {
		if (data.accent) {
			document.documentElement.style.setProperty('--accent', data.accent);
		}
	}

	function setPaper(value) {
		paper = value === 'a4' ? 'a4' : 'a5';
		applyLayout();
	}

	function setOrientation(value) {
		orientation = value === 'portrait' ? 'portrait' : 'landscape';
		applyLayout();
	}

	function applyLayout() {
		var portrait = byId('orientation-portrait');
		var landscape = byId('orientation-landscape');
		var pageStyle = byId('shippilot-dynamic-page-style');

		document.body.classList.remove('paper-a5', 'paper-a4', 'portrait', 'landscape');
		document.body.classList.add('paper-' + paper, orientation);

		if (portrait) {
			portrait.classList.toggle('active', orientation === 'portrait');
		}
		if (landscape) {
			landscape.classList.toggle('active', orientation === 'landscape');
		}

		if (!pageStyle) {
			pageStyle = document.createElement('style');
			pageStyle.id = 'shippilot-dynamic-page-style';
			document.head.appendChild(pageStyle);
		}

		pageStyle.textContent = '@page{size:' + paper.toUpperCase() + ' ' + orientation + ';margin:4mm;}';
	}

	function setZoom(delta) {
		zoom = Math.max(50, Math.min(150, zoom + delta));
		updateZoom();
	}

	function updateZoom() {
		var sheet = byId('sheet');
		var label = byId('zoom-label');
		var topLabel = byId('zoom-label-top');

		if (sheet) {
			sheet.style.transform = 'scale(' + (zoom / 100) + ')';
		}
		if (label) {
			label.textContent = zoom + '%';
		}
		if (topLabel) {
			topLabel.textContent = zoom + '%';
		}
	}

	function printLabel() {
		window.print();
	}

	function copyZpl() {
		var el = byId('dhlwc-zpl');
		if (!el || !el.value) {
			window.alert(data.strings && data.strings.noZpl ? data.strings.noZpl : 'No ZPL is available for this order.');
			return;
		}

		el.style.display = 'block';
		el.select();
		document.execCommand('copy');
		el.style.display = '';
		window.alert(data.strings && data.strings.zplCopied ? data.strings.zplCopied : 'ZPL copied.');
	}

	function downloadZpl() {
		var el = byId('dhlwc-zpl');
		if (!el || !el.value) {
			window.alert(data.strings && data.strings.noZpl ? data.strings.noZpl : 'No ZPL is available for this order.');
			return;
		}

		downloadBlob(
			new Blob([el.value], { type: 'text/plain;charset=utf-8' }),
			data.zplFilename || 'shippilot-label.zpl'
		);
	}

	function mm(value) {
		return Math.round(value * 11.811);
	}

	function wrap(ctx, text, x, y, width, lineHeight, maxLines) {
		var words = String(text || '').replace(/\n/g, ' ').split(' ');
		var lineText = '';
		var lines = 0;
		var test;
		var index;

		for (index = 0; index < words.length; index++) {
			test = lineText + words[index] + ' ';
			if (ctx.measureText(test).width > width && index > 0) {
				ctx.fillText(lineText, x, y);
				lineText = words[index] + ' ';
				y += lineHeight;
				lines++;
				if (maxLines && lines >= maxLines - 1) {
					break;
				}
			} else {
				lineText = test;
			}
		}

		if (lineText) {
			ctx.fillText(lineText.trim(), x, y);
		}
	}

	function drawBars(ctx, text, x, y, width, height) {
		var source = '*' + String(text || '').toUpperCase() + '*';
		var bits = '';
		var index;
		var bit;
		var code;
		var barWidth;

		for (index = 0; index < source.length; index++) {
			code = source.charCodeAt(index);
			for (bit = 0; bit < 7; bit++) {
				bits += (code >> bit) & 1 ? '1110' : '10';
			}
			bits += '000';
		}

		barWidth = width / bits.length;
		ctx.fillStyle = '#000';

		for (index = 0; index < bits.length; index++) {
			if (bits[index] === '1') {
				ctx.fillRect(x + index * barWidth, y, Math.max(1, barWidth), height);
			}
		}
	}

	function drawQr(ctx, x, y, size, seed) {
		var cell = size / 21;
		var value = 0;
		var index;
		var row;
		var column;

		function finder(px, py) {
			ctx.fillStyle = '#111';
			ctx.fillRect(x + px * cell, y + py * cell, 7 * cell, 7 * cell);
			ctx.fillStyle = '#fff';
			ctx.fillRect(x + (px + 1) * cell, y + (py + 1) * cell, 5 * cell, 5 * cell);
			ctx.fillStyle = '#111';
			ctx.fillRect(x + (px + 2) * cell, y + (py + 2) * cell, 3 * cell, 3 * cell);
		}

		ctx.strokeStyle = '#111';
		ctx.lineWidth = 3;
		ctx.strokeRect(x, y, size, size);
		finder(1, 1);
		finder(13, 1);
		finder(1, 13);

		for (index = 0; index < String(seed).length; index++) {
			value += String(seed).charCodeAt(index);
		}

		for (row = 0; row < 21; row++) {
			for (column = 0; column < 21; column++) {
				if ((row * column + value + column) % 5 === 0 && row > 8 && column > 8) {
					ctx.fillRect(x + column * cell, y + row * cell, cell, cell);
				}
			}
		}
	}

	function drawCanvas() {
		var landscape = orientation === 'landscape';
		var a4 = paper === 'a4';
		var width = landscape ? (a4 ? 1600 : 1200) : (a4 ? 1120 : 840);
		var height = landscape ? (a4 ? 1120 : 840) : (a4 ? 1600 : 1200);
		var accent = data.accent || '#ffcc00';
		var canvas = document.createElement('canvas');
		var ctx;
		var x = 24;
		var y = 24;
		var w = width - 48;
		var h = height - 48;
		var header = landscape ? h * 0.12 : h * 0.11;
		var address = landscape ? h * 0.20 : h * 0.19;
		var barcode = landscape ? h * 0.28 : h * 0.27;
		var metrics = landscape ? h * 0.13 : h * 0.12;
		var content = landscape ? h * 0.17 : h * 0.20;
		var footer = h - header - address - barcode - metrics - content;
		var side;
		var main;
		var q;
		var tx;
		var noteWidth;
		var noteHeight;

		canvas.width = width;
		canvas.height = height;
		ctx = canvas.getContext('2d');

		ctx.fillStyle = '#fff';
		ctx.fillRect(0, 0, width, height);
		ctx.strokeStyle = '#111';
		ctx.lineWidth = 2;
		ctx.strokeRect(x, y, w, h);

		ctx.fillStyle = accent;
		ctx.fillRect(x, y, w, header);
		ctx.strokeRect(x, y, w, header);
		ctx.fillStyle = '#111';
		ctx.font = 'bold ' + Math.round(header * 0.25) + 'px Arial';
		ctx.fillText(data.sender || '', x + w * 0.03, y + header * 0.58);
		ctx.textAlign = 'right';
		ctx.fillText(data.title || '', x + w * 0.97, y + header * 0.58);
		ctx.textAlign = 'left';
		y += header;

		ctx.strokeRect(x, y, w, address);
		ctx.beginPath();
		ctx.moveTo(x + w / 2, y);
		ctx.lineTo(x + w / 2, y + address);
		ctx.stroke();
		ctx.font = 'bold ' + Math.round(address * 0.08) + 'px Arial';
		ctx.fillText('SENDER', x + w * 0.02, y + address * 0.18);
		ctx.fillText('RECIPIENT', x + w * 0.52, y + address * 0.18);
		ctx.font = 'bold ' + Math.round(address * 0.14) + 'px Arial';
		ctx.fillText(data.sender || '', x + w * 0.02, y + address * 0.38);
		ctx.fillText(data.recipient || '', x + w * 0.52, y + address * 0.38);
		ctx.font = Math.round(address * 0.085) + 'px Arial';
		wrap(ctx, data.senderAddress, x + w * 0.02, y + address * 0.58, w * 0.42, address * 0.11, 2);
		wrap(ctx, data.recipientAddress, x + w * 0.52, y + address * 0.58, w * 0.42, address * 0.11, 2);
		ctx.font = 'bold ' + Math.round(address * 0.08) + 'px Arial';
		ctx.fillText('Tel: ' + (data.senderPhone || ''), x + w * 0.02, y + address * 0.88);
		ctx.fillText('Tel: ' + (data.recipientPhone || ''), x + w * 0.52, y + address * 0.88);
		y += address;

		side = w * 0.27;
		main = w - side;
		ctx.strokeRect(x, y, w, barcode);
		ctx.beginPath();
		ctx.moveTo(x + main, y);
		ctx.lineTo(x + main, y + barcode);
		ctx.moveTo(x + main, y + barcode / 3);
		ctx.lineTo(x + w, y + barcode / 3);
		ctx.moveTo(x + main, y + barcode / 3 * 2);
		ctx.lineTo(x + w, y + barcode / 3 * 2);
		ctx.stroke();
		ctx.textAlign = 'center';
		ctx.font = 'bold ' + Math.round(barcode * 0.09) + 'px Arial';
		ctx.fillText('ORDER BARCODE', x + main / 2, y + barcode * 0.18);
		drawBars(ctx, data.reference, x + main * 0.06, y + barcode * 0.32, main * 0.88, barcode * 0.34);
		ctx.font = 'bold ' + Math.round(barcode * 0.14) + 'px Arial';
		ctx.fillText('>:' + (data.reference || ''), x + main / 2, y + barcode * 0.82);
		ctx.textAlign = 'left';
		ctx.font = 'bold ' + Math.round(barcode * 0.055) + 'px Arial';
		ctx.fillText('REFERENCE ID', x + main + side * 0.08, y + barcode * 0.17);
		ctx.fillText('BILL OF LANDING ID', x + main + side * 0.08, y + barcode * 0.50);
		ctx.fillText('DATE / TIME', x + main + side * 0.08, y + barcode * 0.83);
		ctx.font = 'bold ' + Math.round(barcode * 0.08) + 'px Arial';
		ctx.fillText(data.reference || '', x + main + side * 0.08, y + barcode * 0.28);
		ctx.fillText(data.billOfLandingId || '', x + main + side * 0.08, y + barcode * 0.61);
		ctx.fillText(data.date || '', x + main + side * 0.08, y + barcode * 0.94);
		y += barcode;

		ctx.strokeRect(x, y, w, metrics);
		ctx.beginPath();
		ctx.moveTo(x + w / 3, y);
		ctx.lineTo(x + w / 3, y + metrics);
		ctx.moveTo(x + 2 * w / 3, y);
		ctx.lineTo(x + 2 * w / 3, y + metrics);
		ctx.stroke();
		ctx.textAlign = 'center';
		ctx.font = 'bold ' + Math.round(metrics * 0.13) + 'px Arial';
		ctx.fillText('PIECE', x + w / 6, y + metrics * 0.35);
		ctx.fillText('KG/VOL.', x + w / 2, y + metrics * 0.35);
		ctx.fillText('SHIPMENT NO', x + 5 * w / 6, y + metrics * 0.35);
		ctx.font = 'bold ' + Math.round(metrics * 0.28) + 'px Arial';
		ctx.fillText('1 / 1', x + w / 6, y + metrics * 0.72);
		ctx.fillText((data.kg || 1) + ' / ' + (data.desi || 1), x + w / 2, y + metrics * 0.72);
		ctx.fillText(data.shipmentId || '-', x + 5 * w / 6, y + metrics * 0.72);
		ctx.textAlign = 'left';
		y += metrics;

		ctx.strokeRect(x, y, w, content);
		ctx.font = 'bold ' + Math.round(content * 0.10) + 'px Arial';
		ctx.fillText('CONTENT', x + w * 0.02, y + content * 0.22);
		ctx.font = Math.round(content * 0.10) + 'px Arial';
		ctx.fillText(data.content || 'Product', x + w * 0.02, y + content * 0.44);
		ctx.font = 'bold ' + Math.round(content * 0.10) + 'px Arial';
		ctx.fillText('PIECE BARCODE:', x + w * 0.02, y + content * 0.72);
		ctx.font = Math.round(content * 0.10) + 'px Arial';
		ctx.fillText(data.pieceBarcode || '', x + w * 0.02, y + content * 0.88);
		y += content;

		ctx.strokeRect(x, y, w, footer);
		q = Math.min(footer * 0.72, w * 0.10);
		drawQr(ctx, x + w * 0.02, y + (footer - q) / 2, q, data.reference);
		ctx.font = 'bold ' + Math.round(footer * 0.10) + 'px Arial';
		tx = x + w * 0.02 + q + w * 0.02;
		ctx.fillText('Order No: ' + (data.orderNumber || ''), tx, y + footer * 0.25);
		ctx.fillText('Reference: ' + (data.reference || ''), tx, y + footer * 0.45);
		ctx.fillText('Created: ' + (data.date || ''), tx, y + footer * 0.65);
		ctx.fillText('Type: ' + (data.type || ''), tx, y + footer * 0.85);
		noteWidth = w * 0.30;
		noteHeight = footer * 0.58;
		ctx.fillStyle = accent;
		ctx.fillRect(x + w - noteWidth - w * 0.02, y + (footer - noteHeight) / 2, noteWidth, noteHeight);
		ctx.fillStyle = '#111';
		ctx.font = 'bold ' + Math.round(noteHeight * 0.16) + 'px Arial';
		wrap(ctx, data.note, x + w - noteWidth - w * 0.005, y + (footer - noteHeight) / 2 + noteHeight * 0.34, noteWidth - w * 0.03, noteHeight * 0.22, 3);

		return canvas;
	}

	function downloadPng() {
		var canvas = drawCanvas();
		var anchor = document.createElement('a');

		anchor.href = canvas.toDataURL('image/png');
		anchor.download = data.pngFilename || 'shippilot-label.png';
		document.body.appendChild(anchor);
		anchor.click();
		document.body.removeChild(anchor);
	}

	function b64bytes(source) {
		var binary = window.atob(source.split(',')[1]);
		var array = new Uint8Array(binary.length);
		var index;

		for (index = 0; index < binary.length; index++) {
			array[index] = binary.charCodeAt(index);
		}

		return array;
	}

	function ascii(source) {
		var array = new Uint8Array(source.length);
		var index;

		for (index = 0; index < source.length; index++) {
			array[index] = source.charCodeAt(index) & 255;
		}

		return array;
	}

	function join(parts) {
		var length = 0;
		var offset = 0;
		var output;

		parts.forEach(function (part) {
			length += part.length;
		});

		output = new Uint8Array(length);

		parts.forEach(function (part) {
			output.set(part, offset);
			offset += part.length;
		});

		return output;
	}

	function pdfFromJpeg(jpg, imageWidth, imageHeight) {
		var pageWidth = paper === 'a4' ? 595 : 420;
		var pageHeight = paper === 'a4' ? 842 : 595;
		var temp;
		var margin = 18;
		var maxWidth;
		var maxHeight;
		var ratio;
		var drawWidth;
		var drawHeight;
		var posX;
		var posY;
		var content;
		var objects = [];
		var head;
		var offsets = [0];
		var current;
		var xref;
		var index;

		if (orientation === 'landscape') {
			temp = pageWidth;
			pageWidth = pageHeight;
			pageHeight = temp;
		}

		maxWidth = pageWidth - margin * 2;
		maxHeight = pageHeight - margin * 2;
		ratio = Math.min(maxWidth / imageWidth, maxHeight / imageHeight);
		drawWidth = imageWidth * ratio;
		drawHeight = imageHeight * ratio;
		posX = (pageWidth - drawWidth) / 2;
		posY = (pageHeight - drawHeight) / 2;
		content = 'q\n' + drawWidth.toFixed(2) + ' 0 0 ' + drawHeight.toFixed(2) + ' ' + posX.toFixed(2) + ' ' + posY.toFixed(2) + ' cm\n/Im0 Do\nQ\n';

		objects.push(ascii('1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n'));
		objects.push(ascii('2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n'));
		objects.push(ascii('3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' + pageWidth + ' ' + pageHeight + '] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>\nendobj\n'));
		objects.push(join([
			ascii('4 0 obj\n<< /Type /XObject /Subtype /Image /Width ' + imageWidth + ' /Height ' + imageHeight + ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' + jpg.length + ' >>\nstream\n'),
			jpg,
			ascii('\nendstream\nendobj\n')
		]));
		objects.push(ascii('5 0 obj\n<< /Length ' + content.length + ' >>\nstream\n' + content + 'endstream\nendobj\n'));

		head = ascii('%PDF-1.4\n%\xE2\xE3\xCF\xD3\n');
		current = head.length;

		objects.forEach(function (object) {
			offsets.push(current);
			current += object.length;
		});

		xref = 'xref\n0 6\n0000000000 65535 f \n';
		for (index = 1; index < offsets.length; index++) {
			xref += String(offsets[index]).padStart(10, '0') + ' 00000 n \n';
		}

		return join([head].concat(objects).concat([
			ascii(xref + 'trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n' + current + '\n%%EOF')
		]));
	}

	function downloadPdf() {
		var canvas = drawCanvas();
		var jpg = b64bytes(canvas.toDataURL('image/jpeg', 0.92));
		var blob = new Blob([pdfFromJpeg(jpg, canvas.width, canvas.height)], { type: 'application/pdf' });

		downloadBlob(blob, data.pdfFilename || 'shippilot-label.pdf');
	}

	function downloadBlob(blob, name) {
		var anchor = document.createElement('a');

		anchor.href = URL.createObjectURL(blob);
		anchor.download = name;
		document.body.appendChild(anchor);
		anchor.click();
		document.body.removeChild(anchor);
		window.setTimeout(function () {
			URL.revokeObjectURL(anchor.href);
		}, 1000);
	}

	function bindEvents() {
		var paperSize = byId('paper-size');

		if (paperSize) {
			paperSize.addEventListener('change', function () {
				setPaper(paperSize.value);
			});
		}

		document.querySelectorAll('[data-orientation]').forEach(function (button) {
			button.addEventListener('click', function () {
				setOrientation(button.getAttribute('data-orientation'));
			});
		});

		document.querySelectorAll('[data-zoom]').forEach(function (button) {
			button.addEventListener('click', function () {
				setZoom(parseInt(button.getAttribute('data-zoom'), 10) || 0);
			});
		});

		document.querySelectorAll('[data-toggle]').forEach(function (input) {
			input.addEventListener('change', function () {
				var label = byId('label');
				if (label) {
					label.classList.toggle(input.getAttribute('data-toggle'), !input.checked);
				}
			});
		});

		document.querySelectorAll('[data-action]').forEach(function (button) {
			button.addEventListener('click', function () {
				var action = button.getAttribute('data-action');
				if (action === 'copy-zpl') {
					copyZpl();
				} else if (action === 'download-zpl') {
					downloadZpl();
				} else if (action === 'print-label') {
					printLabel();
				} else if (action === 'download-pdf') {
					downloadPdf();
				} else if (action === 'download-png') {
					downloadPng();
				}
			});
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		setPageAccent();
		bindEvents();
		applyLayout();
		updateZoom();
	});
}());
