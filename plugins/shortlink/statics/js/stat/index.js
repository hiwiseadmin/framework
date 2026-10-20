import api from '../common/api';
const $ = window.jQuery;

    'use strict';

    var $logTable, chart;

    export default {
        index() {
            $logTable = $('#log-table');
            $logTable.bootstrapTable();

            $('#stat-link-id').on('change', this.loadStats.bind(this));

            var chartDom = document.getElementById('trend-chart');
            if (window.echarts && chartDom) chart = window.echarts.init(chartDom);
            this.loadStats();
        },

        loadStats() {
            var linkId = $('#stat-link-id').val();
            var self = this;

            api.post('/plugin/shortlink/stat/getList', {link_id: linkId}, {
                success(res) {
                    if (res.code === 200 && res.data && res.data.rows && res.data.rows.length) {
                        var row = res.data.rows[0];
                        $('#stat-total').text(row.total_visits || 0);
                        $('#stat-unique').text(row.unique_visits || 0);
                        $('#stat-today').text(row.today_visits || 0);
                    } else {
                        $('#stat-total,#stat-unique,#stat-today').text(0);
                    }
                }
            });

            api.post('/plugin/shortlink/stat/getTop', {link_id: linkId}, {
                success(res) {
                    if (res.code === 200) {
                        var devices = res.data.devices || [];
                        var html = devices.map(function(item) {
                            return (item.device || 'unknown') + ': ' + item.count;
                        }).join('<br>');
                        $('#stat-device').html(html || '暂无数据');
                    }
                }
            });

            api.post('/plugin/shortlink/stat/getTrend', {link_id: linkId, days: 7}, {
                success(res) {
                    if (res.code === 200) self.renderChart(res.data);
                }
            });
        },

        renderChart(data) {
            if (!chart) return;
            var dates = data.map(function(item) { return item.date; });
            var counts = data.map(function(item) { return item.count; });
            chart.setOption({
                tooltip: {trigger: 'axis'},
                xAxis: {type: 'category', data: dates},
                yAxis: {type: 'value', minInterval: 1},
                series: [{data: counts, type: 'line', smooth: true, areaStyle: {}}]
            });
        }
    };
