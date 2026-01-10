// assets/js/ble.js
// Capacitor Bluetooth Module for Air Scales

import { BleClient } from '@capacitor-community/bluetooth-le';

const BLE_SERVICE_UUID = '12345678-1234-1234-1234-123456789abc';
const BLE_SENSOR_CHAR_UUID = '87654321-4321-4321-4321-cba987654321';
const BLE_COEFFS_CHAR_UUID = '11111111-2222-3333-4444-555555555555';

const AirScalesBLE = {
  isCapacitor: false,
  isInitialized: false,
  connectedDeviceId: null,
  listeners: [],

  // Initialize BLE - call this on app start
  async init() {
    // Check if we're in Capacitor
    if (!window.Capacitor || !window.Capacitor.isNativePlatform()) {
      console.log('⚠️ Not in Capacitor app - Bluetooth disabled');
      return false;
    }

    try {
      await BleClient.initialize();
      this.isCapacitor = true;
      this.isInitialized = true;
      console.log('✅ Capacitor Bluetooth initialized');

      // Try auto-reconnect if we have a saved device
      await this.autoReconnect();

      return true;
    } catch (error) {
      console.error('❌ Bluetooth init failed:', error);
      return false;
    }
  },

  // Check if Bluetooth is available
  isAvailable() {
    return this.isCapacitor && this.isInitialized;
  },

  // Check if connected
  isConnected() {
    return !!this.connectedDeviceId;
  },

  // Scan and show device picker
async scanAndConnect() {
  if (!this.isAvailable()) {
    throw new Error('Bluetooth not available. Please use the Air Scales app.');
  }

  try {
    const device = await BleClient.requestDevice({
      namePrefix: 'AirScale',
    });

    console.log('📱 Device selected:', device);
    
    // Extract WiFi MAC from device name (e.g., "AirScale-9C:13:9E:BA:DC:90")
    const wifiMac = this.extractMacFromName(device.name);
    
    await this.connectToDevice(device.deviceId, device.name, wifiMac);
    return device;
  } catch (error) {
    console.error('❌ Scan failed:', error);
    throw error;
  }
},

// Add this new function
extractMacFromName(name) {
  if (!name) return null;
  // Match pattern like "AirScale-XX:XX:XX:XX:XX:XX"
  const match = name.match(/AirScale-([0-9A-Fa-f:]{17})/);
  return match ? match[1].toUpperCase() : null;
},

  // Connect to a specific device
  async connectToDevice(deviceId, deviceName = 'Unknown', wifiMac = null) {
  if (!this.isAvailable()) {
    throw new Error('Bluetooth not available');
  }

  try {
    if (this.connectedDeviceId) {
      await this.disconnect();
    }

    await BleClient.connect(deviceId, (disconnectedDeviceId) => {
      console.log('⚡ Device disconnected:', disconnectedDeviceId);
      this.connectedDeviceId = null;
      this.notifyListeners('disconnected', { deviceId: disconnectedDeviceId });
    });

    this.connectedDeviceId = deviceId;

    // Save for auto-reconnect
    localStorage.setItem('airscales_ble_device_id', deviceId);
    localStorage.setItem('airscales_ble_device_name', deviceName);
    if (wifiMac) {
      localStorage.setItem('airscales_ble_wifi_mac', wifiMac);
    }

    await this.startSensorNotifications();

    console.log('✅ Connected to:', deviceName, 'WiFi MAC:', wifiMac);
    this.notifyListeners('connected', { deviceId, deviceName, wifiMac });

    return true;
  } catch (error) {
    console.error('❌ Connection failed:', error);
    localStorage.removeItem('airscales_ble_device_id');
    localStorage.removeItem('airscales_ble_device_name');
    localStorage.removeItem('airscales_ble_wifi_mac');
    throw error;
  }
},

  // Auto-reconnect to saved device
  async autoReconnect() {
    const savedDeviceId = localStorage.getItem('airscales_ble_device_id');
    const savedDeviceName = localStorage.getItem('airscales_ble_device_name');

    if (!savedDeviceId) {
      console.log('📱 No saved device to reconnect');
      return false;
    }

    console.log('🔄 Attempting auto-reconnect to:', savedDeviceName);

    try {
      await this.connectToDevice(savedDeviceId, savedDeviceName);
      console.log('✅ Auto-reconnect successful');
      return true;
    } catch (error) {
      console.log('⚠️ Auto-reconnect failed (device may be out of range):', error.message);
      return false;
    }
  },

  // Start sensor notifications
  async startSensorNotifications() {
    if (!this.connectedDeviceId) return;

    try {
      await BleClient.startNotifications(
        this.connectedDeviceId,
        BLE_SERVICE_UUID,
        BLE_SENSOR_CHAR_UUID,
        (value) => {
          const data = this.parseDataView(value);
          this.notifyListeners('data', data);
        }
      );
      console.log('📡 Sensor notifications started');
    } catch (error) {
      console.error('❌ Failed to start notifications:', error);
    }
  },

  // Parse DataView to JSON
  parseDataView(dataView) {
    try {
      const decoder = new TextDecoder();
      const jsonString = decoder.decode(dataView);
      return JSON.parse(jsonString);
    } catch (error) {
      console.error('Failed to parse BLE data:', error);
      return null;
    }
  },

  // Send coefficients to device
  async sendCoefficients(coefficients, targetMac = null) {
  if (!this.connectedDeviceId) {
    throw new Error('No device connected');
  }

  try {
    const encoder = new TextEncoder();
    const payload = {
      intercept: coefficients.intercept || 0,
      air_pressure_coeff: coefficients.air_pressure_coeff || 0,
      ambient_pressure_coeff: coefficients.ambient_pressure_coeff || 0,
      air_temp_coeff: coefficients.air_temp_coeff || 0,
      target_mac: targetMac || ''
    };
    
    const data = encoder.encode(JSON.stringify(payload));
    
    await BleClient.write(
      this.connectedDeviceId,
      BLE_SERVICE_UUID,
      BLE_COEFFS_CHAR_UUID,
      data
    );
    
    console.log('✅ Coefficients sent to:', targetMac || 'hub (self)');
  } catch (error) {
    console.error('❌ Failed to send coefficients:', error);
    throw error;
  }
},

  // Disconnect
  async disconnect() {
    if (!this.connectedDeviceId) return;

    try {
      await BleClient.disconnect(this.connectedDeviceId);
      console.log('🔌 Disconnected');
    } catch (error) {
      console.error('Disconnect error:', error);
    }

    this.connectedDeviceId = null;
  },

  // Forget saved device
  forgetDevice() {
    localStorage.removeItem('airscales_ble_device_id');
    localStorage.removeItem('airscales_ble_device_name');
    this.disconnect();
    console.log('🗑️ Device forgotten');
  },

  // Event listener system
  addListener(callback) {
    this.listeners.push(callback);
    return () => {
      this.listeners = this.listeners.filter(l => l !== callback);
    };
  },

  notifyListeners(event, data) {
    this.listeners.forEach(callback => {
      try {
        callback(event, data);
      } catch (error) {
        console.error('Listener error:', error);
      }
    });
  },

  // Get saved device info
  getSavedDevice() {
  return {
    deviceId: localStorage.getItem('airscales_ble_device_id'),
    deviceName: localStorage.getItem('airscales_ble_device_name'),
    wifiMac: localStorage.getItem('airscales_ble_wifi_mac'),
  };
}
};

// Make it globally available
window.AirScalesBLE = AirScalesBLE;

export default AirScalesBLE;