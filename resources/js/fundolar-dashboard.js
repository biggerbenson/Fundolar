(function () {
	'use strict';

	var d = typeof fundolarDash === 'undefined' ? null : fundolarDash;
	var canvas = document.getElementById('fundolar-chart-canvas');
	if (!d || !canvas || !canvas.getContext) {
		return;
	}

	var labels = d.labels || [];
	var values = d.values || [];
	var ctx = canvas.getContext('2d');
	var w = canvas.width;
	var h = canvas.height;
	ctx.clearRect(0, 0, w, h);

	if (!labels.length) {
		ctx.fillStyle = '#787c82';
		ctx.font = '14px system-ui,sans-serif';
		ctx.fillText('No data yet', 12, 24);
		return;
	}

	var pad = { t: 12, r: 12, b: 28, l: 36 };
	var innerW = w - pad.l - pad.r;
	var innerH = h - pad.t - pad.b;
	var maxV = Math.max.apply(null, values.concat([1]));

	function xAt(i) {
		return pad.l + (innerW * i) / Math.max(1, labels.length - 1);
	}

	function yAt(v) {
		return pad.t + innerH * (1 - v / maxV);
	}

	ctx.strokeStyle = '#e8e8e8';
	ctx.lineWidth = 1;
	for (var g = 0; g <= 4; g++) {
		var gy = pad.t + (innerH * g) / 4;
		ctx.beginPath();
		ctx.moveTo(pad.l, gy);
		ctx.lineTo(pad.l + innerW, gy);
		ctx.stroke();
	}

	var points = values.map(function (v, i) {
		return { x: xAt(i), y: yAt(v) };
	});

	ctx.beginPath();
	ctx.moveTo(points[0].x, pad.t + innerH);
	points.forEach(function (p) {
		ctx.lineTo(p.x, p.y);
	});
	ctx.lineTo(points[points.length - 1].x, pad.t + innerH);
	ctx.closePath();
	ctx.fillStyle = 'rgba(40, 167, 69, 0.12)';
	ctx.fill();

	ctx.beginPath();
	points.forEach(function (p, i) {
		if (i === 0) {
			ctx.moveTo(p.x, p.y);
		} else {
			ctx.lineTo(p.x, p.y);
		}
	});
	ctx.strokeStyle = '#28a745';
	ctx.lineWidth = 2;
	ctx.stroke();

	points.forEach(function (p) {
		ctx.beginPath();
		ctx.arc(p.x, p.y, 3.5, 0, Math.PI * 2);
		ctx.fillStyle = '#28a745';
		ctx.fill();
		ctx.strokeStyle = '#fff';
		ctx.lineWidth = 1.5;
		ctx.stroke();
	});

	ctx.fillStyle = '#50575e';
	ctx.font = '10px system-ui,sans-serif';
	ctx.textAlign = 'center';
	var step = Math.ceil(labels.length / 6);
	for (var j = 0; j < labels.length; j += step) {
		ctx.save();
		ctx.translate(xAt(j), h - 8);
		ctx.rotate(-Math.PI / 5);
		ctx.fillText(labels[j], 0, 0);
		ctx.restore();
	}
})();
