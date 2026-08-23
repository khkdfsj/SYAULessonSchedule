<script>
	export default {
		onLaunch: function() {
			console.log('App Launch')
		},
		onShow: function() {
			console.log('App Show')
		},
		onHide: function() {
			console.log('App Hide')
		}
	}
</script>

<style lang="scss">
	/*每个页面公共css */
	@import "common/style/common-style.scss";

	uni-modal {
		z-index: 3000 !important;
	}

	uni-modal .uni-modal {
		z-index: 3001 !important;
	}
</style>
